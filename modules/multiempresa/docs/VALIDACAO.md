# Validação — Multiempresa 1.2.8

Executada em ambiente descartável com Perfex 3.4.1 licenciado, PHP 8.4.24 e MariaDB 11.8.6. Nenhuma alteração aplicada ao servidor do usuário. O módulo NFS-e não foi modificado.

| Verificação executada | Resultado |
| --- | ---: |
| Regressão nativa: módulos, wizard, identidades, organizações, DNS, logos, backups, exclusão e DOM | 798 asserções e verificações HTTP adicionais aprovadas |
| Atualização automática de módulos, falhas, recuperação e exclusão de estados | 49 asserções aprovadas |
| Primeiro acesso HTTP, ordem núcleo/módulo e execução concorrente | 3 verificações aprovadas |
| Unitária | 46 asserções aprovadas |
| Adaptador de módulos | 21 asserções aprovadas |
| Rotas | 9 asserções aprovadas |
| Respostas públicas de erro | 20 asserções aprovadas |
| Sintaxe PHP | 155 arquivos aprovados |

As 79 asserções de DOM estão incluídas na regressão nativa. DNS usa provedor simulado, sem mutações externas. Fixtures de compatibilidade de esquemas não equivalem a testar distribuições completas do Perfex 3.2.x.

Evidências desta versão: `native-1.2.8-results.txt`, `automatic-modules-1.2.8-results.txt`, `unit-1.2.8-results.txt`, `module_runtime-1.2.8-results.txt`, `routes-1.2.8-results.txt`, `tenant_error-1.2.8-results.txt` e `syntax-1.2.8-results.txt`.

## Atualizações automáticas

Testes com banco exclusivo sem prefixo e banco compartilhado com prefixo, módulos ativos, instalados desativados e somente disponíveis:

- Atualização de dois módulos com classes de migration de mesmo número, em processos separados.
- Execução somente das migrations pendentes, sem reexecutar ativação, criar outro administrador ou modificar o onboarding concluído.
- Preservação de empresa suspensa e módulo desativado; ausência de atribuição de novos módulos a outras empresas.
- Troca de arquivos na mesma versão e atualização de versão sem migration SQL artificial.
- Falha real após UPDATE e ALTER TABLE: restauração automática dos dados, DDL, versão instalada e arquivos anteriores; retomada posterior sem repetir migrations concluídas.
- Recuperação de publicação interrompida depois de o worker aplicar SQL, sem repetir a execução nem criar outro backup.
- Recuperação de worker interrompido com dados e DDL parciais antes da manutenção de núcleo.
- Lacuna de migration e downgrade recusados, preservando a versão funcional. Pacote corrigido retomado automaticamente.
- Cron nativo atualiza empresa suspensa sem alterar sua situação.
- Primeiro acesso HTTP atualiza o núcleo e depois o módulo; execução concorrente executa a migration uma única vez.

Os testes reproduzíveis estão em `tests/automatic_modules.php` e `tests/automatic_modules_http.py`. O harness HTTP exige o mesmo ambiente descartável de `tests/native_http.py`, com Perfex licenciado e MySQL/MariaDB isolado. Não executar contra dados de produção.

## Escopo e limitações

Migrations de terceiros precisam ser válidas para o Perfex. Falhas ou sequências incompletas são diagnosticadas e restauradas; não há geração de SQL para substituir migrations ausentes. A infraestrutura nativa de backups continua recusando estruturas fora do contrato genérico, como triggers ou rotinas sem adaptador próprio, antes de alterar a empresa.

A verificação de interface por DOM não equivale a aprovação visual em navegador. O Chromium não abriu neste ambiente; nenhuma nova aprovação visual é declarada. Testes HTTP, PHP e banco são reais.
