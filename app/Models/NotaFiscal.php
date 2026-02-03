<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotaFiscal extends Model
{
    protected $table = 'notas_fiscais';
    
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'sale_id',
        'fiscal_data_id',
        'tipo',
        'numero',
        'serie',
        'chave_acesso',
        'protocolo',
        'status',
        'data_emissao',
        'ambiente',
        'provedor_nome',
        'valor_total',
        'valor_produtos',
        'valor_frete',
        'valor_desconto',
        'cliente_nome',
        'cliente_documento',
        'cliente_email',
        'cliente_telefone',
        'cliente_endereco',
        'natureza',
        'cfop',
        'itens_json',
        'xml_assinado',
        'pdf_url',
        'mensagem_sefaz',
    ];

    protected $casts = [
        'valor_total' => 'decimal:2',
        'valor_produtos' => 'decimal:2',
        'valor_frete' => 'decimal:2',
        'valor_desconto' => 'decimal:2',
        'data_emissao' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function fiscalData()
    {
        return $this->belongsTo(FiscalData::class);
    }
}
