# Correção 0.8.9 — Sincronização de eventos históricos do ADN

## Base preservada

Aplicada sobre o pacote publicado 0.8.8, versão 8, conferido byte a byte com o código antes das alterações. SHA-256 da base: `3de408c0f25029f068946aa87e7186f84002b5f5dd8337d73e18ee4c4d0c8c31`.

## Causa confirmada no portal

Na validação autenticada de 18/11/2026 (America/Bahia), o ADN respondeu HTTP **200**, situação `DOCUMENTOS_LOCALIZADOS`, com **50 documentos**. O leitor recusou um evento porque o campo `nDFSe` estava ausente. A falha abortava o lote, preservando corretamente o cursor, mas impedindo todos os documentos posteriores de serem arquivados. O acervo permanecia em 898 notas emitidas e duas recebidas, com as últimas emitidas em dezembro de 2024.

O XSD oficial histórico `tiposEventos_v1.00.xsd` define **`TCInfEvento/nDFe`**. Os esquemas posteriores usam **`nDFSe`**. O leitor passa a aceitar os dois nomes do mesmo campo sem transformar o XML nem criar um número alternativo.

Fonte oficial consultada e pacote obtido durante a investigação:
https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/leiaute-e-esquemas-antigos/xsd_pl_nfse_1-00-producao.zip/view

A comparação dos pacotes disponíveis 0.8.2, 0.8.3, 0.8.4, 0.8.5, 0.8.6, 0.8.7 e 0.8.8 encontrou o mesmo `FiscalEvent.php`, com a exigência exclusiva de `nDFSe`. Portanto, não foi atribuída a introdução desse erro às versões 0.8.3/0.8.4 nem à política de intervalo. A versão 0.8.8 corrigiu separadamente o bloqueio 409 anterior da fila; esta correção trata a falha XML identificada depois que a consulta voltou a chegar ao ADN.

## Alterações pontuais

- Número do registro obtido de exatamente um filho direto `nDFSe` ou `nDFe`, no namespace nacional.
- Ausência dos dois, duplicidade, coexistência dos nomes, conteúdo composto, valor inválido e namespace incorreto continuam recusados.
- Validações de registro, assinatura estrutural, tipo, identificadores, sequência, datas, chave, ambiente e cancelamento preservadas. A validação estrutural não equivale à verificação criptográfica da cadeia ICP-Brasil.
- XML recebido preservado literalmente, inclusive na gravação criptografada e no processamento de eventos de cancelamento.
- Exceção própria, compatível com `InvalidArgumentException`, permite diagnóstico com mensagens internas fixas, sem expor XML, identificadores ou mensagens arbitrárias de terceiros.
- Migração 089 vazia para registrar a versão. Nenhuma alteração de SQL, conexão, prefixo, escopo criptográfico ou dados existentes.

## Atualização

Atualizar `modules/nfse_nacional` com este pacote completo e executar a atualização de banco oferecida pelo Perfex para registrar 089. Preservar o acervo e a fila; não desinstalar, zerar o NSU, limpar arquivos nem recadastrar o certificado.

A fila retoma o último NSU confirmado e continua os lotes. O cron nativo permanece responsável pela execução com a tela fechada. Pausas confirmadas pelo serviço continuam válidas.

## Validação local

- Regressão reproduzida: o novo caso com `nDFe` falha no leitor anterior e passa com a correção.
- 102 verificações de eventos fiscais: leiaute antigo/atual, registro e XML preservados, campos ausentes/duplicados, namespace e chave/ambiente incorretos.
- 164 verificações do cliente ADN: lote misto com evento histórico avança ao último NSU validado, sem pular evento.
- 49 verificações do serviço/acervo: evento histórico é arquivado criptografado sem alterar XML, situação fiscal e DANFSe confirmados; rollback e isolamento preservados.
- 62 suítes PHP aprovadas; sintaxe dos 599 arquivos PHP conferida.
- MariaDB real, contratos CodeIgniter adaptados: quatro escopos de banco/prefixo; credenciais anteriores, migrações retomáveis, CRUD/permissões de alíquotas, fila em vários lotes e automações concorrentes aprovados. APIs e certificados sintéticos nesses testes locais.
- JavaScript e views não alterados nesta correção; as suítes JavaScript não foram repetidas.

## Validação no ambiente real

A versão 0.8.9 foi instalada sobre 0.8.8 pelo gerenciador nativo do portal e a atualização 089 foi concluída. A consulta ao ADN utilizou o certificado já cadastrado, sem exposição ou novo cadastro de credenciais. Foram baixados pelo portal um XML de nota de 2025 (7.757 bytes, raiz NFSe e assinatura presente) e seu DANFSe PDF (198.205 bytes, cabeçalho e término PDF válidos). Os documentos reais não integram este pacote.

A execução **concluiu às 00:49:01 de 18/11/2026 (America/Bahia)**, com **3.500 itens processados em 71 lotes**. O último retorno do ADN foi HTTP 404 com código fiscal **E2220**, situação **NENHUM_DOCUMENTO_LOCALIZADO**, zero itens e indicação explícita de ausência de novos documentos a partir da sequência consultada. A fila ficou **Concluída**, com a pausa subsequente preservada até 01:49:01.

Totais conferidos após atualizar as listagens:

| Acervo | Antes | Após a conclusão |
| --- | ---: | ---: |
| Emitidas | 898 | **4.264** |
| Recebidas | 2 | **108** |

As emitidas passaram de dezembro de 2024 até **17/11/2026**, com documentos de 2025 e 2026. As recebidas alcançaram novembro de 2026. Os totais representam documentos no acervo; o contador de itens inclui os tipos de documento distribuídos pelo serviço. A conclusão se refere a todos os lotes disponibilizados ao contribuinte nessa execução, sem presumir acesso a documentos que o serviço não disponibilize.

Não foram executadas emissão fiscal, transmissão de DPS ou exclusão de documentos nesta validação. Não foi feita nova homologação real dos demais recursos ou das quatro topologias de instalação do Perfex/Multiempresa; a matriz de compatibilidade foi exercitada localmente com MariaDB.
