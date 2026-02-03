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
use Illuminate\Support\Facades\Log;

class NFeService
{
    /**
     * Emitir NFe a partir de uma venda
     */
    public function emitir(int $saleId, array $notaConfig): array
    {
        Log::info('Iniciando emissão de NFe para sale_id: ' . $saleId);

        // Buscar venda com todos os relacionamentos
        $sale = Sale::with([
            'customer',
            'saleItems.product',
            'shipping.shippingAddress'
        ])->findOrFail($saleId);

        // Verificar se venda está paga
        if (!$sale->isPaid()) {
            throw new \Exception('Apenas vendas pagas podem ter NFe emitida');
        }

        // Buscar dados fiscais do lojista
        $fiscalData = FiscalData::where('user_id', $sale->user_id)
            ->where('ativo', true)
            ->firstOrFail();

        // Se número não foi fornecido, buscar último número + 1
        if (!isset($notaConfig['numero'])) {
            $ultimaNota = \App\Models\NotaFiscal::where('user_id', $sale->user_id)
                ->where('tipo', 'NFe')
                ->where('serie', $notaConfig['serie'])
                ->orderBy('numero', 'desc')
                ->first();
            
            $notaConfig['numero'] = $ultimaNota ? ($ultimaNota->numero + 1) : 1;
            Log::info('Número da NFe gerado automaticamente: ' . $notaConfig['numero']);
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
        
        // Salvar XML para debug
        $xmlPath = storage_path('logs/nfe_xml_debug.xml');
        file_put_contents($xmlPath, $xml);
        Log::info('XML salvo em: ' . $xmlPath);

        // Assinar XML
        $xmlAssinado = $tools->signNFe($xml);
        Log::debug('XML assinado com sucesso');

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
            throw new \Exception('Erro de comunicação via soap, ' . $e->getMessage());
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
                    'ambiente' => $fiscalData->ambiente_nfe,
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

                return [
                    'success' => true,
                    'message' => 'NFe emitida com sucesso',
                    'nota_fiscal' => $notaFiscal,
                    'chave' => $notaFiscal->chave_acesso,
                    'protocolo' => $notaFiscal->protocolo,
                ];
            } else {
                // NFe rejeitada
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
            'ambiente' => $fiscalData->ambiente_nfe,
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
     * Criar instância do Tools
     */
    protected function createTools(FiscalData $fiscalData): Tools
    {
        $config = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => (int) config('nfe.ambiente', 2),
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
        // Dados básicos da NFe
        $std = new \stdClass();
        $std->versao = config('nfe.versao');
        $std->Id = null;
        $std->pk_nItem = null;
        $make->taginfNFe($std);

        // IDE - Identificação
        $this->buildTagIde($make, $sale, $fiscalData, $notaConfig);

        // Emitente
        $this->buildTagEmit($make, $fiscalData);

        // Destinatário
        $this->buildTagDest($make, $sale);

        // Itens
        $this->buildItens($make, $sale);

        // Totais
        $this->buildTotais($make, $sale);

        // Transporte
        $this->buildTransporte($make);

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
        $std->tpAmb = (int) ($fiscalData->ambiente_nfe ?? 2);
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
        $std->CEP = preg_replace('/[^0-9]/', '', $fiscalData->cep);
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
        $isHomologacao = ($fiscalData->ambiente_nfe ?? 2) == 2;
        
        if ($isHomologacao) {
            // Destinatário padrão para homologação
            $std->CPF = '05626815236';
            $std->xNome = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        } else {
            $documento = $sale->customer->document ?? '';
            if (strlen($documento) == 11) {
                $std->CPF = $documento;
            } else {
                $std->CNPJ = $documento;
                // TODO: Se cliente tiver IE informada, adicionar aqui
                // $std->IE = $sale->customer->inscricao_estadual ?? null;
            }
            $std->xNome = $sale->customer->name;
        }
        
        // indIEDest: 1=Contribuinte, 2=Isento, 9=Não contribuinte
        // Para CPF sempre 9, para CNPJ verificar se tem IE
        $documento = $sale->customer->document ?? '';
        if (strlen($documento) == 11) {
            $std->indIEDest = 9; // CPF = não contribuinte
        } else {
            // TODO: Se cliente.inscricao_estadual existir, usar 1, senão 9
            $std->indIEDest = 9; // Por padrão não contribuinte
        }
        
        $std->email = $sale->customer->email ?? '';
        $make->tagdest($std);

        // Endereço do destinatário
        $addr = $sale->shipping && $sale->shipping->shippingAddress 
            ? $sale->shipping->shippingAddress 
            : $sale->customer->addresses->first();
            
        if ($addr) {
            $std = new \stdClass();
            $std->xLgr = $addr->street ?? 'Rua Exemplo';
            $std->nro = $addr->number ?? 'SN';
            $std->xCpl = $addr->complement ?? '';
            $std->xBairro = $addr->neighborhood ?? 'Centro';
            $std->cMun = $this->getCodigoMunicipio($addr->city ?? 'São Paulo', $addr->state ?? 'SP');
            $std->xMun = $addr->city ?? 'São Paulo';
            $std->UF = $addr->state ?? 'SP';
            $std->CEP = preg_replace('/[^0-9]/', '', $addr->zip_code ?? '01000000');
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
        
        foreach ($sale->saleItems as $index => $item) {
            $nItem = $index + 1;
            
            // Produto
            $std = new \stdClass();
            $std->item = $nItem;
            $std->cProd = $item->product->sku;
            $std->cEAN = 'SEM GTIN';
            $std->xProd = $item->product->name;
            $std->NCM = '71131900'; // Artigos de joalharia de metais preciosos
            $std->CFOP = $cfopBase;
            $std->uCom = 'UN';
            $std->qCom = $item->quantity;
            $std->vUnCom = number_format($item->unit_price, 10, '.', '');
            $std->vProd = number_format($item->quantity * $item->unit_price, 2, '.', '');
            $std->cEANTrib = 'SEM GTIN';
            $std->uTrib = 'UN';
            $std->qTrib = $item->quantity;
            $std->vUnTrib = number_format($item->unit_price, 10, '.', '');
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
        $std = new \stdClass();
        $std->vBC = 0;
        $std->vICMS = 0;
        $std->vICMSDeson = 0;
        $std->vFCP = 0;
        $std->vBCST = 0;
        $std->vST = 0;
        $std->vFCPST = 0;
        $std->vFCPSTRet = 0;
        $std->vProd = number_format($sale->total_amount, 2, '.', '');
        $std->vFrete = number_format($sale->shipping_amount, 2, '.', '');
        $std->vSeg = 0;
        $std->vDesc = number_format($sale->discount_amount, 2, '.', '');
        $std->vII = 0;
        $std->vIPI = 0;
        $std->vIPIDevol = 0;
        $std->vPIS = 0;
        $std->vCOFINS = 0;
        $std->vOutro = 0;
        $std->vNF = number_format($sale->final_amount, 2, '.', '');
        $std->vTotTrib = number_format($sale->total_amount * 0.18, 2, '.', ''); // Aprox. 18% de impostos
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
        
        // Detalhe do pagamento
        $std = new \stdClass();
        $std->tPag = '01'; // 01=Dinheiro, 03=Cartão de Crédito, 05=Cartão de Débito, etc
        $std->vPag = number_format($sale->final_amount, 2, '.', '');
        
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
}