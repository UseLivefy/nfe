<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NFeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\CertificadoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Health check
Route::get('/health', [HealthController::class, 'check']);

// Rotas protegidas por token
// Route::middleware('api.token')->group(function () {
    
    // NFe
    Route::post('/nfe/emitir', [NFeController::class, 'emitir']);
    Route::post('/nfe/consultar', [NFeController::class, 'consultar']);
    Route::post('/nfe/cancelar', [NFeController::class, 'cancelar']);
    Route::post('/nfe/corrigir', [NFeController::class, 'cartaCorrecao']);
    
    // Certificado Digital
    Route::post('/certificado/upload', [CertificadoController::class, 'upload']);
    Route::post('/certificado/validar', [CertificadoController::class, 'validar']);
    Route::delete('/certificado/remover', [CertificadoController::class, 'remover']);
    Route::get('/certificado/info', [CertificadoController::class, 'info']);
    
    // Inutilizar numeração
    Route::post('/nfe/inutilizar', [NFeController::class, 'inutilizar']);
    
    // Consultar cadastro (CPF/CNPJ)
    Route::post('/cadastro/consultar', [NFeController::class, 'consultarCadastro']);
// });
