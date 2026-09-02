# Frete Melhor Envio para WCFM

Plugin WordPress/WooCommerce para cotacao de frete por vendedor em marketplaces WCFM usando Melhor Envio.

## Operacao

- Cotacao pela API v2 do Melhor Envio.
- Separacao do carrinho em pacotes individuais por seller.
- CEP de origem configuravel ou obtido automaticamente do endereco WCFM.
- Peso e dimensoes padrao quando o cadastro do produto estiver incompleto.
- Servicos Melhor Envio habilitados por padrao para Correios e Jadlog, com restricao opcional por seller.
- Conta geral do administrador ou conta individual por seller.
- Conexao do Melhor Envio por OAuth ou token pessoal.
- Frete de loja nativo do WCFM preservado ao lado das tarifas da API.
- Emissao de etiqueta do Melhor Envio no painel do pedido do WCFM, por seller.

## Configuracao

No admin, acesse `WooCommerce > Configuracoes > Entrega > Frete Melhor Envio`.

1. Escolha o modo de conta: conta geral do administrador ou conta individual por seller.
2. Configure ambiente, OAuth, token central e servicos globais do Melhor Envio.
3. Ajuste cache, simulador e reutilizacao temporaria da ultima cotacao valida.

Por padrao, as novas instalacoes usam os servicos `1,2,17,3,4`: Correios PAC,
Correios SEDEX, Correios Mini Envios, Jadlog Package e Jadlog .Com. O
administrador pode alterar essa lista em `Servicos habilitados`; quando o seller
deixa o campo de servicos em branco, ele herda o padrao definido pelo
marketplace.

Para OAuth do Melhor Envio, cadastre no aplicativo a URL de retorno exibida pelo WordPress:

```text
/wp-admin/admin-post.php?action=frete_marketplace_melhor_envio_callback
```

O ambiente sandbox usa `https://sandbox.melhorenvio.com.br`; producao usa
`https://melhorenvio.com.br`.

As contas conectadas antes da emissao de etiquetas devem ser desconectadas e conectadas novamente para autorizar os escopos:

```text
shipping-calculate shipping-companies cart-read cart-write shipping-checkout shipping-generate shipping-print shipping-tracking orders-read
```

## Melhor Envio

A cotacao usa:

```text
POST /api/v2/me/shipment/calculate
```

A emissao de etiqueta segue o fluxo oficial:

```text
POST /api/v2/me/cart
POST /api/v2/me/shipment/checkout
POST /api/v2/me/shipment/generate
GET  /api/v2/me/imprimir/pdf/{id}
POST /api/v2/me/shipment/print
```

O cliente envia `Authorization: Bearer`, `Accept: application/json`,
`Content-Type: application/json` e um `User-Agent` com o contato configurado.
O download em PDF e tentado primeiro; se o arquivo ainda nao estiver disponivel,
o plugin solicita um link publico de impressao.

## Etiquetas no WCFM

No detalhe do pedido do vendedor, o bloco `Etiquetas Melhor Envio` aparece para
os pedidos que usam o metodo `Frete Melhor Envio`. O seller consegue gerar ou
atualizar somente a etiqueta dos produtos dele; administradores conseguem operar
todos os sellers do pedido.

O pedido precisa estar pago, em processamento ou concluido. Para montar o envio,
o plugin usa:

- servico escolhido no checkout;
- origem e dados do remetente do seller;
- endereco, telefone, e-mail e CPF/CNPJ do comprador;
- produtos, valores, peso e dimensoes do pacote do seller.

As etiquetas geradas ficam gravadas no pedido, separadas por seller, no metadado
`_frete_marketplace_melhor_envio_labels`.

## Compatibilidade

Alguns slugs tecnicos legados continuam registrados para nao quebrar zonas de
entrega ja configuradas, tema ativo e filtros existentes. Eles nao acionam
nenhuma chamada ou dependencia dos Correios.

## Observabilidade

- Logs pelo logger do WooCommerce, fonte `frete-marketplace`.
- Cache de cotacoes com ultima resposta valida por sete dias.
- Relatorio em `WooCommerce > Relatorio de frete`.
- Auditoria por vendedor, gateway, servico, fallback, valor e prazo.

## Requisitos

- PHP 8.0 ou superior.
- WordPress 6.4 ou superior.
- WooCommerce 8.0 ou superior, incluindo HPOS.
- WCFM Marketplace com configuracoes de seller e pacotes multivendedor.

## Validacao recomendada

1. Ative o sandbox do Melhor Envio e conecte uma conta de teste.
2. Cadastre CEP, peso e dimensoes para um seller.
3. Simule o CEP na pagina do produto.
4. Adicione produtos de dois vendedores ao carrinho.
5. Confirme pacotes e tarifas independentes no checkout.
6. Pague ou marque o pedido como processamento.
7. No detalhe do pedido WCFM, gere a etiqueta do seller.
8. Confirme o arquivo/link da etiqueta, os metadados do pedido e os logs.
