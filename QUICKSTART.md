# 🚀 Guia Rápido - API Laravel NFe

## ✅ Configuração Completa

### Arquitetura Simplificada

```
API Go → Envia apenas sale_id → API Laravel → Busca tudo do MySQL → Emite NFe na SEFAZ
```

**Vantagens:**
- ✅ Sem duplicação de dados
- ✅ Laravel acessa diretamente o banco MySQL
- ✅ Apenas 1 requisição com `sale_id`
- ✅ Biblioteca sped-nfe madura e testada

## 🚀 Como Usar

### 1. Subir os containers

```bash
cd /home/galvao/Desktop/Freelas/uselivefy
docker-compose -f docker-compose.dev.yml up mysql api nfe-api --build
```

### 2. Testar Health Check

```bash
curl http://localhost:8001/api/health
```

Resposta esperada:
```json
{
  "status": "ok",
  "service": "Livefy NFe API"
}
```

### 3. Emitir NFe (da API Go)

O handler `EmitirNotaFiscal` em [nota_fiscal.go](../livefy-api/handlers/nota_fiscal.go) já está configurado para usar a API Laravel quando `provedor_nfe = "sefaz"`.

**Fluxo automático:**
1. Frontend faz POST para `/api/notas-fiscais/emitir` com `sale_id`
2. API Go cria registro na tabela `notas_fiscais`
3. API Go chama Laravel enviando apenas `sale_id`
4. Laravel busca venda, cliente, itens, fiscal_data do MySQL
5. Laravel gera XML, assina e envia para SEFAZ
6. Laravel salva na tabela `notas_fiscais`
7. API Go recebe chave de acesso e protocolo
8. Frontend exibe sucesso

## 📡 Endpoint da API Laravel

### POST `/api/nfe/emitir`

**Header:**
```
X-API-Token: livefy_nfe_secret_token_change_me_in_production
```

**Body:**
```json
{
  "sale_id": 123,
  "nota": {
    "numero": 1,
    "serie": "1",
    "natureza": "Venda de mercadoria",
    "cfop": "5102"
  }
}
```

**Resposta:**
```json
{
  "success": true,
  "data": {
    "chave": "35230512345678000190550010000000011234567890",
    "protocolo": "135230000000001",
    "data_autorizacao": "2026-01-28T10:30:00-03:00",
    "xml": "PD94bW...base64...",
    "status": "autorizada",
    "mensagem": "Autorizado o uso da NF-e",
    "nota_fiscal_id": 456
  }
}
```

## 🔧 Configuração

### docker-compose.dev.yml

```yaml
nfe-api:
  ports:
    - "8001:80"
  environment:
    DB_HOST: mysql
    DB_DATABASE: livefy_db
    DB_USERNAME: livefy_user
    DB_PASSWORD: livefy_password_change_me
    API_SECRET_TOKEN: livefy_nfe_secret_token_change_me_in_production

api:
  environment:
    LARAVEL_NFE_API_URL: http://nfe-api:80/api
    LARAVEL_NFE_API_TOKEN: livefy_nfe_secret_token_change_me_in_production
```

## 📊 Tabelas do Banco

Laravel acessa diretamente:
- `sales` - Dados da venda
- `sale_items` - Itens vendidos
- `products` - Informações dos produtos
- `customers` - Dados do cliente
- `shipping_addresses` - Endereço de entrega
- `fiscal_data` - Certificado e dados fiscais do lojista
- `notas_fiscais` - Salva NFe emitida

## 🐛 Troubleshooting

### Erro: "Connection refused"
```bash
# Verificar se containers estão rodando
docker ps | grep livefy

# Ver logs da API Laravel
docker logs livefy-nfe-api
```

### Erro: "Certificado inválido"
- Verifique se o certificado está salvo corretamente na tabela `fiscal_data`
- Teste com `/api/certificado/validar`

### Erro: "SQLSTATE[HY000] [2002] Connection refused"
- API Laravel não consegue conectar no MySQL
- Aguarde o MySQL inicializar completamente
- Verifique credenciais no `.env`

## 📝 Logs

```bash
# Logs em tempo real
docker logs -f livefy-nfe-api

# Logs do Laravel dentro do container
docker exec livefy-nfe-api tail -f /var/www/storage/logs/laravel.log
```

## ✨ Próximos Passos

1. ✅ Configurar dados fiscais no sistema
2. ✅ Fazer upload do certificado digital
3. ✅ Criar uma venda de teste
4. ✅ Emitir primeira NFe em homologação
5. ✅ Validar XML gerado
6. 🚀 **Deploy em produção!**

---

**Status:** ✅ Pronto para uso  
**Última atualização:** Janeiro 2026
