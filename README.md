# Correios Seller Shipping for WCFM

Plugin WordPress/WooCommerce para calculo de frete dos Correios em marketplaces WCFM com multiplos vendedores.

## O que esta base entrega

- Metodo de frete WooCommerce para Correios por seller.
- Separacao de pacotes do carrinho por vendedor quando o WCFM ainda nao tiver separado.
- Origem de envio por vendedor.
- Modo de credenciais por vendedor ou credenciais centralizadas do administrador.
- Cliente HTTP para as APIs oficiais atuais de Preco e Prazo dos Correios.
- Cache de cotacoes via transients.
- Logs de requisicao/resposta.
- Configuracoes administrativas WooCommerce.
- Campos de configuracao logistica dentro do fluxo de settings do WCFM.
- REST API para leitura e gravacao de configuracoes do vendedor.
- Compatibilidade declarada com WooCommerce HPOS.

## APIs dos Correios

A implementacao usa como base os manuais oficiais publicados pelos Correios para:

- API Token
- API Preco
- API Prazo

Os endpoints atuais usados pela camada `CorreiosClient` sao:

- `https://api.correios.com.br/token/v1/autentica`
- `https://api.correios.com.br/preco/v1/nacional`
- `https://api.correios.com.br/prazo/v3/v1/nacional`

As credenciais e codigos de servicos podem variar por contrato. Por isso, os servicos ficam configuraveis no admin e por vendedor.

## Estrutura

```text
correios-seller.php
src/
  Admin/
  Correios/
  Orders/
  Repository/
  Rest/
  Shipping/
  Support/
  WCFM/
assets/admin/
```

## Proximos passos naturais

- Validar payloads contra o contrato real dos Correios.
- Adicionar emissao de pre-postagem/etiquetas.
- Persistir rastreamentos em tabela propria.
- Criar painel React/Vue dedicado.
- Adicionar adaptadores para transportadoras privadas.
