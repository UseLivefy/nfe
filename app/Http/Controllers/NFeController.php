<?php

namespace App\Http\Controllers;

use App\Services\NFeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NFeController extends Controller
{
    protected $nfeService;

    public function __construct(NFeService $nfeService)
    {
        $this->nfeService = $nfeService;
    }

    /**
     * Emitir NFe
     */
    public function emitir(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'sale_id' => 'required|integer|exists:sales,id',
                'nota_config' => 'required|array',
                'nota_config.numero' => 'nullable|integer',
                'nota_config.natureza' => 'required|string',
                'nota_config.cfop' => 'required|string',
                'nota_config.observacoes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->emitir(
                $request->input('sale_id'),
                $request->input('nota_config')
            );

            return response()->json([
                'success' => true,
                'message' => 'NFe emitida com sucesso',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao emitir NFe: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao emitir NFe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Consultar NFe na SEFAZ
     */
    public function consultar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'chave' => 'required|string|size:44',
                'dados_fiscais' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->consultar(
                $request->input('chave'),
                $request->input('dados_fiscais')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao consultar NFe: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar NFe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancelar NFe
     */
    public function cancelar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'chave' => 'required|string|size:44',
                'protocolo' => 'required|string',
                'motivo' => 'required|string|min:15|max:255',
                'dados_fiscais' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->cancelar(
                $request->input('chave'),
                $request->input('protocolo'),
                $request->input('motivo'),
                $request->input('dados_fiscais')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao cancelar NFe',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Carta de Correção
     */
    public function cartaCorrecao(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'chave' => 'required|string|size:44',
                'protocolo' => 'required|string',
                'correcao' => 'required|string|min:15|max:1000',
                'dados_fiscais' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->cartaCorrecao(
                $request->input('chave'),
                $request->input('protocolo'),
                $request->input('correcao'),
                $request->input('dados_fiscais')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao enviar carta de correção: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao enviar carta de correção',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inutilizar numeração
     */
    public function inutilizar(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'numero_inicial' => 'required|integer',
                'numero_final' => 'required|integer',
                'serie' => 'required|string',
                'motivo' => 'required|string|min:15|max:255',
                'dados_fiscais' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->inutilizar(
                $request->input('numero_inicial'),
                $request->input('numero_final'),
                $request->input('serie'),
                $request->input('motivo'),
                $request->input('dados_fiscais')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao inutilizar numeração: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao inutilizar numeração',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar certificado digital
     */
    public function validarCertificado(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'certificado' => 'required|string',
                'senha' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->validarCertificado(
                $request->input('certificado'),
                $request->input('senha')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Certificado inválido',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Consultar cadastro CPF/CNPJ na SEFAZ
     */
    public function consultarCadastro(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'uf' => 'required|string|size:2',
                'documento' => 'required|string',
                'dados_fiscais' => 'required|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 400);
            }

            $result = $this->nfeService->consultarCadastro(
                $request->input('uf'),
                $request->input('documento'),
                $request->input('dados_fiscais')
            );

            return response()->json([
                'success' => true,
                'data' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao consultar cadastro: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao consultar cadastro',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
