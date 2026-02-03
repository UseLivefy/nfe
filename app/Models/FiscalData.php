<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalData extends Model
{
    protected $table = 'fiscal_data';
    
    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'cnpj',
        'razao_social',
        'nome_fantasia',
        'inscricao_estadual',
        'inscricao_municipal',
        'regime_tributario',
        'cep',
        'logradouro',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'codigo_municipio',
        'email',
        'telefone',
        'certificado_digital',
        'certificado_nome',
        'certificado_senha',
        'certificado_validade_ate',
        'ambiente_nfe',
        'serie_nfe',
        'proximo_numero_nfe',
        'ambiente_nfse',
        'proximo_numero_nfse',
        'provedor_nfe',
        'provedor_api_key',
        'provedor_webhook_token',
        'ativo',
    ];

    protected $casts = [
        'certificado_validade_ate' => 'datetime',
        'ativo' => 'boolean',
        'proximo_numero_nfe' => 'integer',
        'proximo_numero_nfse' => 'integer',
    ];

    protected $hidden = [
        'certificado_digital',
        'certificado_senha',
        'provedor_api_key',
        'provedor_webhook_token',
    ];
}
