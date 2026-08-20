<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\FiscalData;
use App\Models\NotaFiscal;
use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\DA\NFe\Danfe;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NFeService
{
    /**
     * Emitir NFe a partir de uma venda
     */
    public function emitir(int $saleId, array $notaConfig): array
    {
        Log::info('Iniciando emissão de NFe para sale_id: ' . $saleId);

        // Verificar se já existe nota fiscal AUTORIZADA para esta venda
        $notaAutorizada = NotaFiscal::where('sale_id', $saleId)
            ->where('status', 'autorizada')
            ->first();
            
        if ($notaAutorizada) {
            throw new \Exception(
                'Já existe uma nota fiscal autorizada para esta venda. ' .
                "NFe #{$notaAutorizada->numero} - Série {$notaAutorizada->serie}"
            );
        }
        
        // Verificar se existe nota com erro (para log)
        $notaComErro = NotaFiscal::where('sale_id', $saleId)
            ->where('status', 'erro')
            ->first();

        if ($notaComErro) {
            Log::info('Nota anterior com erro encontrada, removendo para reemissão', [
                'numero' => $notaComErro->numero,
                'mensagem' => $notaComErro->mensagem_sefaz
            ]);
            $notaComErro->delete();
        }

        // Buscar venda com todos os relacionamentos
        $sale = Sale::with([
            'customer',
            'saleItems.product',
            'shipping.shippingAddress'
        ])->findOrFail($saleId);

        // Verificar se venda está paga
        // if (!$sale->isPaid()) {
            // throw new \Exception('Apenas vendas pagas podem ter NFe emitida. Status da venda: ' . $sale->status);
        // }

        // Buscar dados fiscais do lojista
        $fiscalData = FiscalData::where('user_id', $sale->user_id)
            ->where('ativo', true)
            ->firstOrFail();

        // Se série não foi fornecida, usar a configurada nos dados fiscais
        if (!isset($notaConfig['serie'])) {
            $notaConfig['serie'] = $fiscalData->serie_n_fe ?? '1';
            Log::info('Série da NFe obtida dos dados fiscais: ' . $notaConfig['serie']);
        }

        // Se número não foi fornecido, usar o próximo número dos dados fiscais
        if (!isset($notaConfig['numero'])) {
            // Usar proximo_numero_nfe e incrementar
            $notaConfig['numero'] = $fiscalData->proximo_numero_n_fe ?? 1;
            Log::info('Número da NFe gerado: ' . $notaConfig['numero']);
            
            // Incrementar o próximo número nos dados fiscais para garantir unicidade
            $fiscalData->proximo_numero_n_fe = $notaConfig['numero'] + 1;
            $fiscalData->save();
            Log::info('Próximo número atualizado para: ' . $fiscalData->proximo_numero_n_fe);
        }

        // Validar certificado
        if (empty($fiscalData->certificado_nome)) {
            throw new \Exception('Certificado digital não configurado');
        }
        
        // Verificar se arquivo existe no storage
        $certPath = storage_path('app/certificados/' . $fiscalData->user_id . '/' . $fiscalData->certificado_nome);
        if (!file_exists($certPath)) {
            throw new \Exception('Arquivo de certificado não encontrado no servidor');
        }

        // Criar Tools
        $tools = $this->createTools($fiscalData);
        $make = new Make();

        // Montar XML da NFe
        $this->buildNFe($make, $sale, $fiscalData, $notaConfig);

        // Gerar XML
        $xml = $make->getXML();
        Log::debug('XML da NFe gerado');
        
        // Garantir que diretório existe
        $logDir = storage_path('logs');
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Salvar XML para debug
        $xmlPath = storage_path('logs/nfe_xml_debug.xml');
        file_put_contents($xmlPath, $xml);
        Log::info('XML salvo em: ' . $xmlPath);
        
        // Log do conteúdo do XML (primeiros 1000 caracteres)
        Log::info('Início do XML gerado: ' . substr($xml, 0, 1000));

        // Assinar XML
        try {
            $xmlAssinado = $tools->signNFe($xml);
            Log::debug('XML assinado com sucesso');
            
            // Salvar XML assinado
            $xmlAssinadoPath = storage_path('logs/nfe_xml_assinado.xml');
            file_put_contents($xmlAssinadoPath, $xmlAssinado);
            Log::info('XML assinado salvo em: ' . $xmlAssinadoPath);
        } catch (\Exception $e) {
            Log::error('Erro ao assinar XML: ' . $e->getMessage());
            throw $e;
        }

        // Enviar para SEFAZ
        // indSinc: 0=Assíncrono, 1=Síncrono (obrigatório para lote com 1 NFe)
        try {
            $idLote = str_pad(rand(1, 99999), 15, '0', STR_PAD_LEFT);
            $indSinc = 1; // Síncrono para lote com 1 NFe
            $compactar = false;
            $retxmls = [];
            
            $response = $tools->sefazEnviaLote([$xmlAssinado], $idLote, $indSinc, $compactar, $retxmls);
            Log::debug('Resposta SEFAZ EnviaLote: ' . $response);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar lote para SEFAZ: ' . $e->getMessage());
            
            // Salvar registro com erro de comunicação (updateOrCreate evita duplicate key em chave_acesso)
            NotaFiscal::updateOrCreate(
                ['sale_id' => $sale->id, 'numero' => $notaConfig['numero'], 'serie' => $notaConfig['serie']],
                [
                'user_id' => $sale->user_id,
                'fiscal_data_id' => $fiscalData->id,
                'tipo' => 'NFe',
                'chave_acesso' => null,
                'protocolo' => null,
                'status' => 'erro',
                'data_emissao' => now(),
                'ambiente' => $fiscalData->ambiente_n_fe,
                'provedor_nome' => 'sefaz',
                'valor_total' => $sale->final_amount,
                'valor_produtos' => $sale->total_amount,
                'valor_frete' => $sale->shipping_amount,
                'valor_desconto' => $sale->discount_amount,
                'cliente_nome' => $sale->customer->name,
                'cliente_documento' => $sale->customer->document ?? '',
                'cliente_email' => $sale->customer->email ?? '',
                'cliente_telefone' => $sale->customer->phone ?? '',
                'natureza' => $notaConfig['natureza'],
                'cfop' => $notaConfig['cfop'],
                'itens_json' => json_encode($sale->saleItems),
                'xml_assinado' => $xmlAssinado ?? '',
                'mensagem_sefaz' => 'Erro de comunicação: ' . $e->getMessage(),
                ]);
            
            throw new \Exception('Erro de comunicação com SEFAZ: ' . $e->getMessage());
        }

        // Processar resposta
        $stdCl = new Standardize();
        $std = $stdCl->toStd($response);

        Log::info('Status SEFAZ EnviaLote', [
            'cStat' => $std->cStat ?? 'N/A',
            'xMotivo' => $std->xMotivo ?? 'N/A'
        ]);

        // Em modo síncrono: cStat 104 = Lote processado
        // Verificar se há protocolo de autorização
        if (isset($std->protNFe)) {
            Log::info('Protocolo encontrado em modo síncrono');
            
            $protocolo = $std->protNFe;
            
            if ($protocolo->infProt->cStat == 100) {
                // NFe autorizada
                Log::info('NFe autorizada', [
                    'chave' => $protocolo->infProt->chNFe,
                    'protocolo' => $protocolo->infProt->nProt
                ]);
                
                // Protocolar NFe
                $xmlProtocolado = Complements::toAuthorize($xmlAssinado, $response);
                
                // Salvar nota fiscal
                $notaFiscal = NotaFiscal::create([
                    'user_id' => $sale->user_id,
                    'sale_id' => $sale->id,
                    'fiscal_data_id' => $fiscalData->id,
                    'tipo' => 'NFe',
                    'numero' => $notaConfig['numero'],
                    'serie' => $notaConfig['serie'],
                    'chave_acesso' => $protocolo->infProt->chNFe,
                    'protocolo' => $protocolo->infProt->nProt,
                    'status' => 'autorizada',
                    'data_emissao' => now(),
                    'ambiente' => $fiscalData->ambiente_n_fe,
                    'provedor_nome' => 'sefaz',
                    'valor_total' => $sale->final_amount,
                    'valor_produtos' => $sale->total_amount,
                    'valor_frete' => $sale->shipping_amount,
                    'valor_desconto' => $sale->discount_amount,
                    'cliente_nome' => $sale->customer->name,
                    'cliente_documento' => $sale->customer->document ?? '',
                    'cliente_email' => $sale->customer->email ?? '',
                    'cliente_telefone' => $sale->customer->phone ?? '',
                    'natureza' => $notaConfig['natureza'],
                    'cfop' => $notaConfig['cfop'],
                    'itens_json' => json_encode($sale->saleItems),
                    'xml_assinado' => $xmlProtocolado,
                    'mensagem_sefaz' => $protocolo->infProt->xMotivo,
                ]);

                // Gerar e salvar PDF
                try {
                    $this->gerarPDF($tools, $xmlProtocolado, $protocolo->infProt->chNFe);
                    Log::info('PDF gerado com sucesso para chave: ' . $protocolo->infProt->chNFe);
                } catch (\Throwable $e) {
                    Log::error('Erro ao gerar PDF: ' . $e->getMessage());
                    // Não lançar exceção, PDF pode ser gerado depois
                }

                return [
                    'success' => true,
                    'message' => 'NFe emitida com sucesso',
                    'nota_fiscal' => $notaFiscal,
                    'chave' => $notaFiscal->chave_acesso,
                    'protocolo' => $notaFiscal->protocolo,
                ];
            } else {
                // NFe rejeitada - Salvar no banco com status de erro (updateOrCreate evita duplicate key)
                $notaFiscal = NotaFiscal::updateOrCreate(
                    ['sale_id' => $sale->id, 'numero' => $notaConfig['numero'], 'serie' => $notaConfig['serie']],
                    [
                    'user_id' => $sale->user_id,
                    'fiscal_data_id' => $fiscalData->id,
                    'tipo' => 'NFe',
                    'chave_acesso' => $protocolo->infProt->chNFe ?? null,
                    'protocolo' => null,
                    'status' => 'erro',
                    'data_emissao' => now(),
                    'ambiente' => $fiscalData->ambiente_n_fe,
                    'provedor_nome' => 'sefaz',
                    'valor_total' => $sale->final_amount,
                    'valor_produtos' => $sale->total_amount,
                    'valor_frete' => $sale->shipping_amount,
                    'valor_desconto' => $sale->discount_amount,
                    'cliente_nome' => $sale->customer->name,
                    'cliente_documento' => $sale->customer->document ?? '',
                    'cliente_email' => $sale->customer->email ?? '',
                    'cliente_telefone' => $sale->customer->phone ?? '',
                    'natureza' => $notaConfig['natureza'],
                    'cfop' => $notaConfig['cfop'],
                    'itens_json' => json_encode($sale->saleItems),
                    'xml_assinado' => $xmlAssinado,
                    'mensagem_sefaz' => $protocolo->infProt->xMotivo,
                    ]
                );
                
                throw new \Exception("NFe rejeitada: {$protocolo->infProt->cStat} - {$protocolo->infProt->xMotivo}");
            }
        }

        // Se não veio protNFe e for cStat 103, processar assíncrono
        if ($std->cStat != 103) {
            throw new \Exception("Erro SEFAZ: {$std->cStat} - {$std->xMotivo}");
        }
        $recibo = $std->infRec->nRec;
        sleep(2); // Aguardar processamento

        $responseConsulta = $tools->sefazConsultaRecibo($recibo);
        Log::debug('Resposta SEFAZ ConsultaRecibo: ' . $responseConsulta);
        
        $stdConsulta = $stdCl->toStd($responseConsulta);
        
        Log::info('Status SEFAZ ConsultaRecibo', [
            'cStat' => $stdConsulta->cStat ?? 'N/A',
            'xMotivo' => $stdConsulta->xMotivo ?? 'N/A',
            'hasProtNFe' => isset($stdConsulta->protNFe) ? 'Sim' : 'Não'
        ]);

        if (!isset($stdConsulta->protNFe)) {
            $erro = "NFe não foi autorizada. Status: " . ($stdConsulta->cStat ?? 'N/A') . " - " . ($stdConsulta->xMotivo ?? 'N/A');
            throw new \Exception($erro);
        }

        $protocolo = $stdConsulta->protNFe;

        if ($protocolo->infProt->cStat != 100) {
            // NFe rejeitada no processo assíncrono - Salvar no banco com status de erro (updateOrCreate evita duplicate key)
            $notaFiscal = NotaFiscal::updateOrCreate(
                ['sale_id' => $sale->id, 'numero' => $notaConfig['numero'], 'serie' => $notaConfig['serie']],
                [
                'user_id' => $sale->user_id,
                'fiscal_data_id' => $fiscalData->id,
                'tipo' => 'NFe',
                'chave_acesso' => $protocolo->infProt->chNFe ?? null,
                'protocolo' => null,
                'status' => 'erro',
                'data_emissao' => now(),
                'ambiente' => $fiscalData->ambiente_n_fe,
                'provedor_nome' => 'sefaz',
                'valor_total' => $sale->final_amount,
                'valor_produtos' => $sale->total_amount,
                'valor_frete' => $sale->shipping_amount,
                'valor_desconto' => $sale->discount_amount,
                'cliente_nome' => $sale->customer->name,
                'cliente_documento' => $sale->customer->document ?? '',
                'cliente_email' => $sale->customer->email ?? '',
                'cliente_telefone' => $sale->customer->phone ?? '',
                'natureza' => $notaConfig['natureza'],
                'cfop' => $notaConfig['cfop'],
                'itens_json' => json_encode($sale->saleItems),
                'xml_assinado' => $xmlAssinado,
                'mensagem_sefaz' => $protocolo->infProt->xMotivo,
                ]
            );
            
            throw new \Exception("NFe rejeitada: {$protocolo->infProt->cStat} - {$protocolo->infProt->xMotivo}");
        }

        // Protocolar NFe (adicionar protocolo ao XML)
        $xmlProtocolado = Complements::toAuthorize($xmlAssinado, $responseConsulta);

        // Salvar nota fiscal no banco
        $notaFiscal = NotaFiscal::create([
            'user_id' => $sale->user_id,
            'sale_id' => $sale->id,
            'fiscal_data_id' => $fiscalData->id,
            'tipo' => 'NFe',
            'numero' => $notaConfig['numero'],
            'serie' => $notaConfig['serie'],
            'chave_acesso' => $protocolo->infProt->chNFe,
            'protocolo' => $protocolo->infProt->nProt,
            'status' => 'autorizada',
            'data_emissao' => now(),
            'ambiente' => $fiscalData->ambiente_n_fe,
            'provedor_nome' => 'sefaz',
            'valor_total' => $sale->final_amount,
            'valor_produtos' => $sale->total_amount,
            'valor_frete' => $sale->shipping_amount,
            'valor_desconto' => $sale->discount_amount,
            'cliente_nome' => $sale->customer->name,
            'cliente_documento' => $sale->customer->document ?? '',
            'cliente_email' => $sale->customer->email ?? '',
            'cliente_telefone' => $sale->customer->phone ?? '',
            'natureza' => $notaConfig['natureza'],
            'cfop' => $notaConfig['cfop'],
            'itens_json' => json_encode($sale->saleItems),
            'xml_assinado' => $xmlProtocolado,
            'mensagem_sefaz' => $protocolo->infProt->xMotivo,
        ]);

        // Gerar e salvar PDF
        try {
            $this->gerarPDF($tools, $xmlProtocolado, $protocolo->infProt->chNFe);
            Log::info('PDF gerado com sucesso para chave: ' . $protocolo->infProt->chNFe);
        } catch (\Throwable $e) {
            Log::error('Erro ao gerar PDF: ' . $e->getMessage());
            // Não lançar exceção, PDF pode ser gerado depois
        }

        Log::info('NFe emitida com sucesso', [
            'chave' => $protocolo->infProt->chNFe,
            'protocolo' => $protocolo->infProt->nProt
        ]);

        return [
            'chave' => $protocolo->infProt->chNFe,
            'protocolo' => $protocolo->infProt->nProt,
            'data_autorizacao' => $protocolo->infProt->dhRecbto,
            'xml' => base64_encode($xmlProtocolado),
            'status' => 'autorizada',
            'mensagem' => $protocolo->infProt->xMotivo,
            'nota_fiscal_id' => $notaFiscal->id,
        ];
    }

    /**
     * Cancelar NFe autorizada (evento de cancelamento, síncrono).
     * Regras SEFAZ: NFe precisa estar autorizada, dentro do prazo (normalmente 24h),
     * com o protocolo de autorização de uso, e o motivo deve ter ao menos 15 caracteres
     * (já validado no Controller).
     */
    public function cancelar(string $chave, string $protocolo, string $motivo): array
    {
        Log::info("Iniciando cancelamento de NFe. Chave: {$chave}");

        $notaFiscal = NotaFiscal::where('chave_acesso', $chave)->first();
        if (!$notaFiscal) {
            throw new \Exception("Nota fiscal não encontrada para a chave informada: {$chave}");
        }

        if ($notaFiscal->status === 'cancelada') {
            throw new \Exception('Esta NFe já está cancelada.');
        }

        if ($notaFiscal->status !== 'autorizada') {
            throw new \Exception("Apenas NFe autorizadas podem ser canceladas. Status atual: {$notaFiscal->status}");
        }

        if ($notaFiscal->protocolo !== $protocolo) {
            throw new \Exception('Protocolo de autorização informado não corresponde ao protocolo registrado para esta NFe.');
        }

        $fiscalData = FiscalData::find($notaFiscal->fiscal_data_id);
        if (!$fiscalData) {
            throw new \Exception('Dados fiscais do emitente não encontrados.');
        }

        $tools = $this->createTools($fiscalData);

        try {
            $response = $tools->sefazCancela($chave, $motivo, $protocolo);
        } catch (\Exception $e) {
            Log::error('Erro de comunicação ao cancelar NFe na SEFAZ: ' . $e->getMessage());
            throw new \Exception('Erro de comunicação com SEFAZ: ' . $e->getMessage());
        }

        $stdCl = new Standardize();
        $std = $stdCl->toStd($response);

        Log::info('Status SEFAZ Cancelamento (lote)', [
            'cStat' => $std->cStat ?? 'N/A',
            'xMotivo' => $std->xMotivo ?? 'N/A',
        ]);

        // cStat 128 = Lote de Evento Processado (não confundir com o cStat do evento em si)
        if ((string) ($std->cStat ?? '') !== '128') {
            throw new \Exception("Lote de cancelamento não processado pela SEFAZ: {$std->cStat} - {$std->xMotivo}");
        }

        $infEvento = $std->retEvento->infEvento ?? null;
        if (!$infEvento) {
            throw new \Exception('Resposta da SEFAZ não contém o evento de cancelamento processado.');
        }

        $cStatEvento = (string) $infEvento->cStat;
        $xMotivoEvento = $infEvento->xMotivo ?? '';

        Log::info('Status SEFAZ Cancelamento (evento)', [
            'cStat' => $cStatEvento,
            'xMotivo' => $xMotivoEvento,
        ]);

        // 101/135 = homologado dentro do prazo, 155 = homologado fora do prazo
        if (!in_array($cStatEvento, ['101', '135', '155'], true)) {
            throw new \Exception("Cancelamento rejeitado pela SEFAZ: {$cStatEvento} - {$xMotivoEvento}");
        }

        $notaFiscal->status = 'cancelada';
        $notaFiscal->status_descricao = $xMotivoEvento;
        $notaFiscal->motivo_cancelamento = $motivo;
        $notaFiscal->data_cancelamento = now()->toDateTimeString();
        $notaFiscal->save();

        Log::info('NFe cancelada com sucesso', [
            'chave' => $chave,
            'nProt_evento' => $infEvento->nProt ?? null,
        ]);

        return [
            'chave' => $chave,
            'protocolo_cancelamento' => $infEvento->nProt ?? null,
            'status' => 'cancelada',
            'cStat' => $cStatEvento,
            'mensagem' => $xMotivoEvento,
            'data_cancelamento' => $notaFiscal->data_cancelamento,
        ];
    }

    /**
     * Criar instância do Tools
     */
    protected function createTools(FiscalData $fiscalData): Tools
    {
        $config = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => (int) ($fiscalData->ambiente_n_fe ?? 2),
            'razaosocial' => $fiscalData->razao_social,
            'siglaUF' => $fiscalData->uf,
            'cnpj' => $fiscalData->cnpj,
            'schemes' => config('nfe.schemes', 'PL_009_V4'),
            'versao' => config('nfe.versao', '4.00'),
            'tokenIBPT' => '',
            'CSC' => '',
            'CSCid' => '000001',
        ];

        $configJson = json_encode($config);

        // Buscar certificado do storage
        $certPath = storage_path('app/certificados/' . $fiscalData->user_id . '/' . $fiscalData->certificado_nome);
        $certificadoContent = file_get_contents($certPath);
        
        $certificate = Certificate::readPfx($certificadoContent, $fiscalData->certificado_senha);

        $tools = new Tools($configJson, $certificate);
        $tools->model('55'); // NFe modelo 55

        return $tools;
    }

    /**
     * Montar XML da NFe
     */
    protected function buildNFe(Make $make, Sale $sale, FiscalData $fiscalData, array $notaConfig): void
    {
        Log::info('Iniciando buildNFe');
        
        // Dados básicos da NFe
        $std = new \stdClass();
        $std->versao = config('nfe.versao');
        $std->Id = null;
        $std->pk_nItem = null;
        $make->taginfNFe($std);
        Log::debug('taginfNFe criada');

        // IDE - Identificação
        $this->buildTagIde($make, $sale, $fiscalData, $notaConfig);
        Log::debug('buildTagIde concluído');

        // Emitente
        $this->buildTagEmit($make, $fiscalData);
        Log::debug('buildTagEmit concluído');

        // Destinatário
        $this->buildTagDest($make, $sale);
        Log::debug('buildTagDest concluído');

        // Itens
        $this->buildItens($make, $sale);
        Log::debug('buildItens concluído');

        // Totais
        $this->buildTotais($make, $sale);
        Log::debug('buildTotais concluído');

        // Transporte
        $this->buildTransporte($make);
        Log::debug('buildTransporte concluído');

        // Pagamento (obrigatório!)
        Log::info('Iniciando buildPagamento');
        try {
            $this->buildPagamento($make, $sale);
            Log::info('buildPagamento executado com sucesso');
        } catch (\Exception $e) {
            Log::error('Erro em buildPagamento: ' . $e->getMessage());
            throw $e;
        }

        // Informações Adicionais
        // if (!empty($notaConfig['observacoes'])) {
        //     $std = new \stdClass();
        //     $std->infCpl = $notaConfig['observacoes'];
        //     $make->taginfAdic($std);
        // }
        
        Log::info('buildNFe concluído com sucesso');
    }

    protected function buildTagIde(Make $make, Sale $sale, FiscalData $fiscalData, array $notaConfig): void
    {
        $std = new \stdClass();
        $std->cUF = $this->getCodigoUF($fiscalData->uf);
        $std->cNF = rand(10000000, 99999999);
        $std->natOp = $notaConfig['natureza'];
        $std->mod = 55;
        $std->serie = $notaConfig['serie'];
        $std->nNF = $notaConfig['numero'];
        $std->dhEmi = date('Y-m-d\TH:i:sP');
        $std->dhSaiEnt = date('Y-m-d\TH:i:sP');
        $std->tpNF = 1;
        
        // Calcular idDest baseado na UF
        $ufDest = $this->getUFDestinatario($sale);
        $std->idDest = ($fiscalData->uf == $ufDest) ? 1 : 2; // 1=Interna, 2=Interestadual
        
        $std->cMunFG = $fiscalData->codigo_municipio;
        $std->tpImp = 1;
        $std->tpEmis = 1;
        $std->cDV = 0;
        $std->tpAmb = (int) ($fiscalData->ambiente_n_fe ?? 2);
        $std->finNFe = 1;
        
        // indFinal: 0=Normal, 1=Consumidor final
        // Quando destinatário é não contribuinte (CPF ou CNPJ sem IE), DEVE ser consumidor final
        $documento = $sale->customer->document ?? '';
        $std->indFinal = (strlen($documento) <= 11) ? 1 : 1; // Sempre consumidor final por padrão
        
        $std->indPres = 1;
        $std->procEmi = 0;
        $std->verProc = '1.0';

        $make->tagide($std);
    }

    protected function buildTagEmit(Make $make, FiscalData $fiscalData): void
    {
        $std = new \stdClass();
        $std->xNome = $fiscalData->razao_social;
        $std->xFant = $fiscalData->nome_fantasia ?? $fiscalData->razao_social;
        $std->CRT = (int) ($fiscalData->regime_tributario ?? 1);
        $std->CNPJ = $fiscalData->cnpj;
        
        // IE: Se não tiver valor, usar "ISENTO"
        $std->IE = (!empty($fiscalData->inscricao_estadual) && trim($fiscalData->inscricao_estadual) !== '') 
            ? $fiscalData->inscricao_estadual 
            : 'ISENTO';
        
        $make->tagemit($std);

        $std = new \stdClass();
        $std->xLgr = $fiscalData->logradouro;
        $std->nro = $fiscalData->numero;
        $std->xCpl = $fiscalData->complemento ?? '';
        $std->xBairro = $fiscalData->bairro;
        $std->cMun = $fiscalData->codigo_municipio;
        $std->xMun = $fiscalData->cidade;
        $std->UF = $fiscalData->uf;
        $cepEmitente = preg_replace('/[^0-9]/', '', $fiscalData->cep);
        if (strlen($cepEmitente) !== 8) {
            throw new \Exception('CEP do emitente inválido: "' . $fiscalData->cep . '" — informe exatamente 8 dígitos nos dados fiscais.');
        }
        $std->CEP = $cepEmitente;
        $std->cPais = '1058';
        $std->xPais = 'BRASIL';
        $std->fone = preg_replace('/[^0-9]/', '', $fiscalData->telefone ?? '');
        $make->tagenderEmit($std);
    }

    protected function buildTagDest(Make $make, Sale $sale): void
    {
        $std = new \stdClass();
        
        // Em homologação, forçar dados específicos conforme Manual da NFe
        $fiscalData = FiscalData::where('user_id', $sale->user_id)->first();
        $isHomologacao = ($fiscalData->ambiente_n_fe ?? 2) == 2;
        $documento = $sale->customer->document ?? '';

        if ($isHomologacao) {
            // Destinatário padrão para homologação
            $std->xNome = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        } else {
            $std->xNome = $sale->customer->name;
        }

        if (strlen($documento) == 11) {
            $std->CPF = $documento;
            $std->indIEDest = 9; // CPF = não contribuinte
        } else {
            $std->CNPJ = $documento;

            // Buscar IE via consultar.io usando a UF do endereço de entrega
            $ufDest = $this->getUFDestinatario($sale);
            $ie = $this->buscarIEPorCNPJ($documento, $ufDest);

            if ($ie !== null) {
                $std->IE = $ie;
                $std->indIEDest = 1; // CNPJ com IE = contribuinte
            } else {
                $std->indIEDest = 9; // IE não encontrada = não contribuinte
            }
        }
        
        $std->email = $sale->customer->email ?? '';
        $make->tagdest($std);

        // Endereço do destinatário
        $addr = $sale->shipping && $sale->shipping->shippingAddress 
            ? $sale->shipping->shippingAddress 
            : $sale->customer->addresses->first();
            
        if ($addr) {
            $cepLimpo = preg_replace('/[^0-9]/', '', $addr->zip_code ?? '');
            if (strlen($cepLimpo) !== 8) {
                throw new \Exception('CEP do destinatário inválido: "' . ($addr->zip_code ?? '') . '" — informe exatamente 8 dígitos no endereço do cliente.');
            }
            $ibgeCode = $this->getIBGECodeByCep($cepLimpo);

            $std = new \stdClass();
            $std->xLgr = $addr->street ?? 'Rua Exemplo';
            $std->nro = $addr->number ?? 'SN';
            $std->xCpl = $addr->complement ?? '';
            $std->xBairro = $addr->neighborhood ?? 'Centro';
            $std->cMun = $ibgeCode ?: $this->getCodigoMunicipio($addr->city ?? 'São Paulo', $addr->state ?? 'SP');
            $std->xMun = $addr->city ?? 'São Paulo';
            $std->UF = $addr->state ?? 'SP';
            $std->CEP = $cepLimpo;
            $std->cPais = '1058';
            $std->xPais = 'BRASIL';
            $std->fone = preg_replace('/[^0-9]/', '', $sale->customer->phone ?? '');
            $make->tagenderDest($std);
        }
    }
    
    protected function getUFDestinatario(Sale $sale): string
    {
        if ($sale->shipping && $sale->shipping->shippingAddress) {
            return $sale->shipping->shippingAddress->state;
        }
        
        $addr = $sale->customer->addresses->first();
        return $addr ? $addr->state : 'SP';
    }

    protected function buildItens(Make $make, Sale $sale): void
    {
        $fiscalData = FiscalData::where('user_id', $sale->user_id)->first();
        $ufDest = $this->getUFDestinatario($sale);
        $cfopBase = ($fiscalData->uf == $ufDest) ? '5102' : '6102'; // 5xxx=Interna, 6xxx=Interestadual
        $isSimples = ($fiscalData->regime_tributario ?? 1) == 1;

        // Distribuição proporcional do frete por item (ICMSTot/vFrete deve = soma de det/prod/vFrete)
        $shippingAmount = (float) ($sale->shipping_amount ?? 0);
        // Distribuição proporcional do desconto por item (ICMSTot/vDesc deve = soma de det/prod/vDesc)
        $discountAmount = (float) ($sale->discount_amount ?? 0);
        // Excluir itens cancelados da NFe
        $activeItems = $sale->saleItems->filter(fn($i) => $i->status !== 'cancelled')->values();
        $totalProd = $activeItems->sum(fn($i) => $i->quantity * $i->unit_price);
        $lastIndex = $activeItems->count() - 1;
        $freightAccumulated = 0.0;
        $discountAccumulated = 0.0;

        // Validar documento do cliente (CPF ou CNPJ é obrigatório na NFe)
        $documento = preg_replace('/\D/', '', $sale->customer->document ?? '');
        if (strlen($documento) !== 11 && strlen($documento) !== 14) {
            throw new \Exception(
                sprintf(
                    'Cliente não possui CPF/CNPJ válido cadastrado. Cadastre o documento antes de emitir a NFe.',
                )
            );
        }

        // Validar NCM de todos os itens ativos antes de gerar o XML
        $itensComNcmInvalido = [];
        foreach ($activeItems as $item) {
            $ncm = preg_replace('/\D/', '', $item->product->ncm ?? '');
            if (strlen($ncm) !== 8) {
                $itensComNcmInvalido[] = sprintf(
                    '%s (SKU: %s) — NCM "%s" inválido (requer 8 dígitos)',
                    $item->product->name,
                    $item->product->sku,
                    $item->product->ncm ?? 'vazio'
                );
            }
        }
        if (!empty($itensComNcmInvalido)) {
            throw new \Exception(
                'NCM inválido nos seguintes produtos: ' . implode('; ', $itensComNcmInvalido)
            );
        }

        foreach ($activeItems as $index => $item) {
            $nItem = $index + 1;

            // Frete proporcional: último item absorve o restante para evitar diferença de centavos
            if ($index === $lastIndex) {
                $itemFreight = round($shippingAmount - $freightAccumulated, 2);
                $itemDiscount = round($discountAmount - $discountAccumulated, 2);
            } else {
                $itemFreight = $totalProd > 0
                    ? round($shippingAmount * ($item->quantity * $item->unit_price) / $totalProd, 2)
                    : 0.0;
                $freightAccumulated += $itemFreight;
                $itemDiscount = $totalProd > 0
                    ? round($discountAmount * ($item->quantity * $item->unit_price) / $totalProd, 2)
                    : 0.0;
                $discountAccumulated += $itemDiscount;
            }

            // Produto
            $std = new \stdClass();
            $std->item = $nItem;
            $std->cProd = $item->product->sku;
            $std->cEAN = 'SEM GTIN';
            $std->xProd = $item->product->name;
            $std->NCM = preg_replace('/\D/', '', $item->product->ncm); // NCM do produto (8 dígitos)
            $std->CFOP = $cfopBase;
            $std->uCom = 'UN';
            $std->qCom = $item->quantity;
            $std->vUnCom = number_format($item->unit_price, 10, '.', '');
            $std->vProd = number_format($item->quantity * $item->unit_price, 2, '.', '');
            $std->cEANTrib = 'SEM GTIN';
            $std->uTrib = 'UN';
            $std->qTrib = $item->quantity;
            $std->vUnTrib = number_format($item->unit_price, 10, '.', '');
            // vFrete e vDesc são TDec_1302Opc: omitir quando zero (0.00 é inválido pelo schema)
            if ($itemFreight > 0) {
                $std->vFrete = number_format($itemFreight, 2, '.', '');
            }
            if ($itemDiscount > 0) {
                $std->vDesc = number_format($itemDiscount, 2, '.', '');
            }
            $std->indTot = 1;
            $make->tagprod($std);

            // Impostos
            $vTotTrib = $item->quantity * $item->unit_price * 0.18; // Aprox. 18% de impostos
            $std = new \stdClass();
            $std->item = $nItem;
            $std->vTotTrib = number_format($vTotTrib, 2, '.', '');
            $make->tagimposto($std);

            // ICMS - Simples Nacional
            if ($isSimples) {
                $std = new \stdClass();
                $std->item = $nItem;
                $std->orig = 0;
                $std->CSOSN = '102'; // Simples Nacional sem permissão de crédito
                $make->tagICMSSN($std);
            } else {
                $std = new \stdClass();
                $std->item = $nItem;
                $std->orig = 0;
                $std->CST = '00';
                $std->modBC = 0;
                $std->vBC = 0;
                $std->pICMS = 0;
                $std->vICMS = 0;
                $make->tagICMS($std);
            }

            // PIS - Simples Nacional
            $std = new \stdClass();
            $std->item = $nItem;
            $std->CST = '07'; // Operação isenta de PIS/COFINS (Simples)
            $make->tagPIS($std);

            // COFINS - Simples Nacional
            $std = new \stdClass();
            $std->item = $nItem;
            $std->CST = '07'; // Operação isenta de PIS/COFINS (Simples)
            $std->vBC = 0;
            $std->pCOFINS = 0;
            $std->vCOFINS = 0;
            $make->tagCOFINS($std);
        }
    }

    protected function buildTotais(Make $make, Sale $sale): void
    {
        // vProd calculado direto dos itens ativos (não usar total_amount do banco que pode estar desatualizado)
        $activeItems = $sale->saleItems->filter(fn($i) => $i->status !== 'cancelled');
        $vProd  = round($activeItems->sum(fn($i) => $i->quantity * $i->unit_price), 2);
        $vFrete = (float) ($sale->shipping_amount ?? 0);
        $vDesc  = (float) ($sale->discount_amount ?? 0);
        $vNF    = round($vProd + $vFrete - $vDesc, 2);

        $std = new \stdClass();
        $std->vBC       = 0;
        $std->vICMS     = 0;
        $std->vICMSDeson = 0;
        $std->vFCP      = 0;
        $std->vBCST     = 0;
        $std->vST       = 0;
        $std->vFCPST    = 0;
        $std->vFCPSTRet = 0;
        $std->vProd     = number_format($vProd,  2, '.', '');
        $std->vFrete    = number_format($vFrete, 2, '.', '');
        $std->vSeg      = 0;
        $std->vDesc     = number_format($vDesc,  2, '.', '');
        $std->vII       = 0;
        $std->vIPI      = 0;
        $std->vIPIDevol = 0;
        $std->vPIS      = 0;
        $std->vCOFINS   = 0;
        $std->vOutro    = 0;
        $std->vNF       = number_format($vNF, 2, '.', '');
        $std->vTotTrib  = number_format($vProd * 0.18, 2, '.', '');
        $make->tagICMSTot($std);
    }

    protected function buildTransporte(Make $make): void
    {
        $std = new \stdClass();
        $std->modFrete = 9;
        $make->tagtransp($std);
    }

    protected function buildPagamento(Make $make, Sale $sale): void
    {
        Log::info('buildPagamento chamado', ['sale_id' => $sale->id, 'final_amount' => $sale->final_amount]);
        
        // Criar stdClass para pagamento
        $std = new \stdClass();
        $std->vTroco = null; // Sem troco
        $make->tagpag($std);
        
        // Detalhe do pagamento — vPag deve igualar vNF (apenas itens ativos + frete - desconto)
        $activeItems = $sale->saleItems->filter(fn($i) => $i->status !== 'cancelled');
        $vProd = round($activeItems->sum(fn($i) => $i->quantity * $i->unit_price), 2);
        $vPag = round(
            $vProd +
            (float) ($sale->shipping_amount ?? 0) -
            (float) ($sale->discount_amount ?? 0),
            2
        );
        $std = new \stdClass();
        $std->tPag = '01'; // 01=Dinheiro, 03=Cartão de Crédito, 05=Cartão de Débito, etc
        $std->vPag = number_format($vPag, 2, '.', '');
        
        Log::info('Chamando tagdetPag', ['tPag' => $std->tPag, 'vPag' => $std->vPag]);
        $make->tagdetPag($std);
        Log::info('tagdetPag executado');
    }

    protected function getCodigoUF(string $uf): int
    {
        $codigos = [
            'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29,
            'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21,
            'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25,
            'PR' => 41, 'PE' => 26, 'PI' => 22, 'RJ' => 33, 'RN' => 24,
            'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35,
            'SE' => 28, 'TO' => 17
        ];
        
        return $codigos[$uf] ?? 0;
    }
    
    protected function getIBGECodeByCep(string $cep): string
    {
        if (strlen($cep) !== 8) {
            return '';
        }

        try {
            $response = Http::timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/");

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['ibge']) && empty($data['erro'])) {
                    Log::debug("ViaCEP [{$cep}]: ibge={$data['ibge']}, localidade={$data['localidade']}, uf={$data['uf']}");
                    return (string) $data['ibge'];
                }
            }
        } catch (\Exception $e) {
            Log::warning("ViaCEP falhou para CEP {$cep}: " . $e->getMessage());
        }

        return '';
    }

    /**
     * Busca IE do CNPJ via API consultar.io e salva em cache permanente.
     * Retorna null se não encontrada (sem lançar exceção).
     */
    protected function buscarIEPorCNPJ(string $cnpj, string $uf): ?string
    {
        $cacheKey = "ie_cnpj_{$cnpj}_uf_{$uf}";

        if (\Cache::has($cacheKey)) {
            Log::debug("IE do CNPJ {$cnpj} obtida do cache para UF {$uf}");
            return \Cache::get($cacheKey);
        }

        $token = '486d2ea1434ae374ccb8e1919578007cb5243970';

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Token {$token}"])
                ->get('https://consultar.io/api/v2/ie/consultar', [
                    'uf'   => $uf,
                    'cnpj' => $cnpj,
                ]);

            $data = $response->json();

            $ie = $data[0]['ie'] ?? null;
            $ieValida = is_string($ie) && preg_match('/^\d+$/', trim($ie));

            if (!$response->successful() || empty($data) || !$ieValida) {
                Log::info("IE não encontrada para CNPJ {$cnpj} na UF {$uf} — usando indIEDest=9");
                return null;
            }

            $ie = trim($ie);

            // Salvar em cache sem TTL (permanente)
            \Cache::forever($cacheKey, $ie);

            Log::info("IE do CNPJ {$cnpj} encontrada e cacheada: {$ie}");

            return $ie;
        } catch (\Exception $e) {
            // Em caso de erro de rede/timeout, logar e retornar null (não bloquear a emissão)
            Log::warning("Erro ao consultar IE para CNPJ {$cnpj} na UF {$uf}: " . $e->getMessage());
            return null;
        }
    }

    protected function getCodigoMunicipio(string $cidade, string $uf): string
    {
        // Mapeamento das principais cidades (adicionar mais conforme necessário)
        $municipios = [
            'São Paulo-SP' => '3550308',
            'Curitiba-PR' => '4106902',
            'Rio de Janeiro-RJ' => '3304557',
            'Belo Horizonte-MG' => '3106200',
            'Brasília-DF' => '5300108',
            'Salvador-BA' => '2927408',
            'Fortaleza-CE' => '2304400',
            'Recife-PE' => '2611606',
            'Porto Alegre-RS' => '4314902',
        ];
        
        $chave = $cidade . '-' . $uf;
        return $municipios[$chave] ?? '3550308'; // Default: São Paulo
    }
    
    /**
     * Gerar PDF da NFe
     */
    protected function gerarPDF(Tools $tools, string $xmlProtocolado, string $chaveAcesso): void
    {
        // Criar diretório se não existir
        $pdfDir = storage_path('app/nfe/pdf');
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        
        // Gerar PDF usando a biblioteca nfephp-da
        $danfe = new Danfe($xmlProtocolado);
        $pdf = $danfe->render();
        
        // Salvar PDF
        $pdfPath = $pdfDir . '/' . $chaveAcesso . '.pdf';
        file_put_contents($pdfPath, $pdf);
        
        Log::info('PDF salvo em: ' . $pdfPath);
    }
}