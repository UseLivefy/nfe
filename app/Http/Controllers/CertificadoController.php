<?php

namespace App\Http\Controllers;

use App\Models\FiscalData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use NFePHP\Common\Certificate;

class CertificadoController extends Controller
{
    /**
     * Upload de certificado digital
     */
    public function upload(Request $request)
    {
        try {
            // Verificar se o arquivo foi enviado
            if (!$request->hasFile('certificado')) {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo de certificado é obrigatório',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer|exists:users,id',
                'senha' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $userId = $request->input('user_id');
            $senha = $request->input('senha');
            $file = $request->file('certificado');
            
            // Validar tamanho (5MB)
            if ($file->getSize() > 5120 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo não pode exceder 5MB'
                ], 400);
            }
            
            // Validar extensão manualmente
            $extension = strtolower($file->getClientOriginalExtension());
            if ($extension !== 'pfx') {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo deve ser do tipo .pfx'
                ], 400);
            }

            // Validar certificado antes de salvar
            $certContent = file_get_contents($file->getRealPath());
            
            try {
                $certificate = Certificate::readPfx($certContent, $senha);
                $certData = openssl_x509_parse($certificate);
            } catch (\Exception $e) {
                Log::error('Erro ao validar certificado: ' . $e->getMessage());
                
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado inválido, senha incorreta ou formato não suportado. Verifique se o certificado usa algoritmos compatíveis (3DES, SHA1).'
                ], 400);
            }

            // Extrair informações do certificado
            $cnpj = $this->extractCNPJFromCert($certData);
            $validadeAte = date('Y-m-d', $certData['validTo_time_t']);
            $nomeCertificado = $certData['subject']['CN'] ?? 'Certificado';

            // Criar diretório do usuário se não existir
            $userCertDir = storage_path('app/certificados/' . $userId);
            if (!file_exists($userCertDir)) {
                mkdir($userCertDir, 0755, true);
            }

            // Gerar nome único para o arquivo
            $fileName = 'certificado_' . $cnpj . '_' . time() . '.pfx';
            $filePath = $userCertDir . '/' . $fileName;

            // Salvar arquivo
            file_put_contents($filePath, $certContent);

            // Buscar ou criar fiscal_data
            $fiscalData = FiscalData::where('user_id', $userId)->first();

            if (!$fiscalData) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados fiscais não encontrados. Configure primeiro os dados fiscais.'
                ], 404);
            }

            // Remover certificado antigo se existir
            if ($fiscalData->certificado_nome) {
                $oldCertPath = storage_path('app/certificados/' . $userId . '/' . $fiscalData->certificado_nome);
                if (file_exists($oldCertPath)) {
                    unlink($oldCertPath);
                }
            }

            // Atualizar fiscal_data
            $fiscalData->update([
                'certificado_nome' => $fileName,
                'certificado_senha' => $senha,
                'certificado_validade_ate' => $validadeAte,
                'certificado_digital' => null, // Limpar conteúdo do banco se existir
            ]);

            Log::info('Certificado digital salvo', [
                'user_id' => $userId,
                'cnpj' => $cnpj,
                'validade' => $validadeAte
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Certificado digital salvo com sucesso',
                'data' => [
                    'nome_certificado' => $nomeCertificado,
                    'cnpj' => $cnpj,
                    'validade_ate' => $validadeAte,
                    'dias_restantes' => floor(($certData['validTo_time_t'] - time()) / 86400),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao salvar certificado: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar certificado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar certificado sem salvar
     */
    public function validar(Request $request)
    {
        
        try {
            // Verificar se o arquivo foi enviado
            if (!$request->hasFile('certificado')) {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo de certificado é obrigatório',
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'senha' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $file = $request->file('certificado');
            $senha = $request->input('senha');
            
            // Validar tamanho (5MB)
            if ($file->getSize() > 5120 * 1024) {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo não pode exceder 5MB'
                ], 400);
            }
            
            // Validar extensão manualmente
            $extension = strtolower($file->getClientOriginalExtension());
            if ($extension !== 'pfx') {
                return response()->json([
                    'success' => false,
                    'message' => 'O arquivo deve ser do tipo .pfx'
                ], 400);
            }
            
            $certContent = file_get_contents($file->getRealPath());

            try {
                $certificate = Certificate::readPfx($certContent, $senha);
                $certData = openssl_x509_parse($certificate);
            } catch (\Exception $e) {
                Log::error('Erro ao validar certificado: ' . $e->getMessage());
                
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado inválido, senha incorreta ou formato não suportado. Verifique se o certificado usa algoritmos compatíveis (3DES, SHA1).'
                ], 400);
            }

            $cnpj = $this->extractCNPJFromCert($certData);
            $diasRestantes = floor(($certData['validTo_time_t'] - time()) / 86400);

            return response()->json([
                'success' => true,
                'message' => 'Certificado válido',
                'data' => [
                    'nome' => $certData['subject']['CN'] ?? 'Não informado',
                    'cnpj' => $cnpj,
                    'validade_inicio' => date('Y-m-d', $certData['validFrom_time_t']),
                    'validade_fim' => date('Y-m-d', $certData['validTo_time_t']),
                    'dias_restantes' => $diasRestantes,
                    'valido' => $diasRestantes > 0,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao validar certificado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remover certificado
     */
    public function remover(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $userId = $request->input('user_id');
            $fiscalData = FiscalData::where('user_id', $userId)->first();

            if (!$fiscalData || !$fiscalData->certificado_nome) {
                return response()->json([
                    'success' => false,
                    'message' => 'Certificado não encontrado'
                ], 404);
            }

            // Remover arquivo
            $certPath = storage_path('app/certificados/' . $userId . '/' . $fiscalData->certificado_nome);
            if (file_exists($certPath)) {
                unlink($certPath);
            }

            // Limpar dados do banco
            $fiscalData->update([
                'certificado_nome' => null,
                'certificado_senha' => null,
                'certificado_validade_ate' => null,
                'certificado_digital' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Certificado removido com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao remover certificado: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover certificado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Informações do certificado atual
     */
    public function info(Request $request)
    {
        try {
            $userId = $request->query('user_id');
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'user_id é obrigatório'
                ], 400);
            }

            $fiscalData = FiscalData::where('user_id', $userId)->first();

            if (!$fiscalData || !$fiscalData->certificado_nome) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'configurado' => false,
                        'mensagem' => 'Nenhum certificado configurado'
                    ]
                ]);
            }

            $certPath = storage_path('app/certificados/' . $userId . '/' . $fiscalData->certificado_nome);
            
            if (!file_exists($certPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Arquivo de certificado não encontrado'
                ], 404);
            }

            $certContent = file_get_contents($certPath);
            $certificate = Certificate::readPfx($certContent, $fiscalData->certificado_senha);
            $certData = openssl_x509_parse($certificate);

            $diasRestantes = floor(($certData['validTo_time_t'] - time()) / 86400);

            return response()->json([
                'success' => true,
                'data' => [
                    'configurado' => true,
                    'nome' => $certData['subject']['CN'] ?? 'Não informado',
                    'cnpj' => $this->extractCNPJFromCert($certData),
                    'validade_inicio' => date('Y-m-d', $certData['validFrom_time_t']),
                    'validade_fim' => date('Y-m-d', $certData['validTo_time_t']),
                    'dias_restantes' => $diasRestantes,
                    'valido' => $diasRestantes > 0,
                    'arquivo' => $fiscalData->certificado_nome,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar info do certificado: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar informações do certificado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Extrair CNPJ do certificado
     */
    protected function extractCNPJFromCert(array $certData): ?string
    {
        // Tentar extrair do CN (Common Name) primeiro
        if (isset($certData['subject']['CN'])) {
            $cn = $certData['subject']['CN'];
            
            // Remover toda pontuação e pegar apenas números
            $apenasNumeros = preg_replace('/[^0-9]/', '', $cn);
            
            // Verificar se tem 14 dígitos (CNPJ)
            if (strlen($apenasNumeros) == 14) {
                return $apenasNumeros;
            }
            
            // Tentar extrair 14 dígitos consecutivos
            if (preg_match('/(\d{14})/', $apenasNumeros, $matches)) {
                return $matches[1];
            }
        }
        
        // Tentar extrair do serialNumber
        if (isset($certData['subject']['serialNumber'])) {
            $serial = $certData['subject']['serialNumber'];
            $apenasNumeros = preg_replace('/[^0-9]/', '', $serial);
            
            if (strlen($apenasNumeros) == 14) {
                return $apenasNumeros;
            }
            
            if (preg_match('/(\d{14})/', $apenasNumeros, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
}
