# Correção pontual 0.8.4 — conexão nativa e prefixo vazio

Base: pacote publicado **0.8.3**, SHA-256 `92e4ad555ae6859a74667b727d2697ee2cdf751e3f1717f7b1054fab14cdb855`. Todos os recursos e todas as migrações anteriores foram preservados.

## Correção

A 0.8.3 já aceitava prefixo vazio no Perfex independente, mas `nfse_nacional_scope()` ainda condicionava o prefixo vazio ao metadado `mode=database` quando a integração Multiempresa estava declarada ativa.

A 0.8.4 remove essa condição. A conexão nativa configurada pelo CRM e o retorno literal de `db_prefix()` determinam as tabelas utilizadas. Prefixo vazio representa tabelas sem prefixo, sem substituição por `tbl`.

Não foi criada configuração, segredo, permissão de banco, cadastro ou protocolo novo de integração. O módulo não cria bancos, não concede privilégios e não assume o provisionamento. Instalador, migrações e repositórios continuam usando a conexão corrente e o prefixo nativo validado.

A integração anterior permanece opcional. O funcionamento nativo não exige `PME_CONTEXT_READY`, `$GLOBALS['pme_context']`, classes ou configuração do Multiempresa. Quando o runtime oferece uma identidade válida da integração já existente, ela permanece no escopo anterior para preservar arquivos e dados cifrados. Uma identidade explicitamente ativa mas inconsistente continua sendo recusada; não se troca silenciosamente o escopo de uma empresa por outro.

## Preservação

- A fórmula de `nfse_nacional_scope()` não foi alterada; apenas foi removida a restrição pelo modo de provisionamento.
- `crypto()`, derivação de chaves, certificados, credenciais, cache, logs e seus formatos não foram alterados.
- Continuam sendo validados os prefixos preenchidos e sua correspondência com um contexto confiável quando a integração estiver ativa.
- GET/POST não fornecem conexão, prefixo ou identidade empresarial.
- Autenticação compartilhada, alíquotas mensais, automações, destinatários e sincronização da 0.8.3 permanecem intactos.
- A migração **084** é vazia, somente para continuidade de versão: não executa SQL nem altera dados.
- Não foi acrescentada operação à desativação. A desinstalação continua sendo uma ação distinta e destrutiva, como já ocorria.

## Validação

MariaDB 11.8.6, com os contratos CodeIgniter adaptados:

| Cenário | Resultado |
| --- | --- |
| Perfex independente com prefixo | 21 verificações aprovadas |
| Perfex independente sem prefixo | 21 verificações aprovadas |
| Multiempresa com banco exclusivo sem prefixo e contexto opcional existente | 21 verificações aprovadas |
| Multiempresa com banco compartilhado e prefixo e contexto opcional existente | 21 verificações aprovadas |
| Banco exclusivo provisionado, operando somente pela conexão nativa, sem flags/contexto/classes Multiempresa | 21 verificações aprovadas |
| Banco compartilhado provisionado, operando somente pela conexão e prefixo nativos, sem flags/contexto/classes Multiempresa | 21 verificações aprovadas |

Total: **126 verificações**. Os cenários exercitam o instalador, o callback real de ativação, reativação, unicidade, isolamento das conexões e leitura das credenciais anteriores. Uma falha de DDL é induzida e a atualização é repetida sem perder os dados ou as etapas concluídas. Verifica-se também que não existe limpeza de dados em hook de desativação do módulo.

Testes complementares: escopo e prefixos (15 verificações), autorização/isolamento (23), sequência de migrações (152), ponte de certificados com código real Multiempresa 1.2.6 (31), sincronização/repositório, logs, worker de automação e hooks nativos. A integridade do ZIP e a sintaxe PHP foram conferidas.

**Não executado:** ativação/desativação e provisionamento no portal real do usuário. Não houve modificação do Multiempresa, criação de bancos de clientes, concessão de privilégios ou chamadas fiscais reais. As bases criadas nos ensaios são exclusivamente descartáveis e pertencem aos testes.

## Atualização

Atualize o conteúdo do módulo sobre a instalação existente e execute a atualização de banco oferecida pelo Perfex para registrar a migração 084. Não desinstale para atualizar. Nenhum cadastro adicional é necessário.
