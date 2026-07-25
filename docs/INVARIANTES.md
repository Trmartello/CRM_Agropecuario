# CRM Agropecuário — Copérdia

## O que este sistema é

Ferramenta comercial e técnica para a equipe agro da Copérdia (RTV, gerência
regional, Controladoria). NÃO é um sistema de gestão de lavoura para o produtor.

Tese: vender por **potencial do produtor**, não por histórico de compra.
Quem nunca comprou pode ser o maior gap do território.

## Invariantes — nunca violar

1. **O Qlik calcula, o CRM exibe.** Nenhum indicador financeiro, margem, share
   ou potencial é recalculado neste código. Tudo vem do `SCORE_QLIK` (read-only,
   sync diário). Se um número precisa existir e não está lá, a solução é pedir a
   medida no Qlik — não implementar a fórmula aqui.

2. **CAR identifica área, não pessoa.** A relação imóvel × produtor é N:M por
   causa de condomínio e posse localizada. Nunca modelar como 1:1, nunca usar
   CAR como chave de cliente.

3. **Geometria é herdada, não desenhada.** Polígonos vêm do SICAR. Não construir
   ferramenta de desenho de talhão.

4. **Dado de produtor é sensível.** CPF, CNPJ, coordenada de propriedade e
   faturamento individual nunca aparecem em log, URL, query string ou mensagem
   de erro.

## Stack

React + Node.js + MySQL 8. Mapas: Leaflet via react-leaflet, renderer canvas.
Geometria em SRID 4326.

## Convenções

- Código, nomes de variável e commits em português.
- Migrations versionadas e reversíveis. Nunca alterar migration já mergeada.
- Nenhum segredo em código. `.env` fora do versionamento.
- Componentes por módulo em `src/modules/<nome>/`, não por tipo de arquivo.

## Como trabalhar comigo

- Antes de implementar, leia o spec correspondente em `docs/specs/`.
- Apresente o plano e espere aprovação antes de escrever código.
- Um PR por item da ordem de implementação do spec. Não adiantar etapas.
- Se o spec estiver ambíguo, pergunte. Não escolha por conta própria em
  regra de negócio.

## Estado atual

Ver `docs/specs/` para as funcionalidades especificadas e o status de cada uma.
