# NFS-e Nacional 0.8.2 — Compatibilidade com banco exclusivo

O erro enviado interrompia a ativação antes da criação das tabelas fiscais: a versão 0.8.1 recusava qualquer prefixo vazio. A versão 0.8.2 aceita prefixo vazio somente quando o contexto confiável do Multiempresa identifica um banco exclusivo e coincide com o prefixo da conexão. Ausência de contexto, prefixo divergente, prefixo malformado e banco compartilhado sem prefixo continuam recusados.

O escopo criptográfico das instalações prefixadas é idêntico ao anterior. O contexto inclui servidor, banco, empresa e organização. Nenhum dado existente é convertido ou recriptografado pela migração 082.

O manifesto declara as 15 tabelas do instalador para o provisionador conceder permissões específicas ao usar banco compartilhado.

## Validação

- 14 verificações de escopo aprovadas, incluindo isolamento por banco, servidor, empresa e organização.
- 14 verificações nativas no Perfex: falha real da 0.8.1, substituição pela 0.8.2, revisão/retomada sem recriação das etapas, preservação de dados, todas as tabelas, ativação/desativação e banco compartilhado com acesso restrito.
- 150 verificações de sequência de migrações aprovadas, sem alteração de dados nas migrações de continuidade.
- 59 de 60 suítes PHP aprovadas. A suíte `ibpt_pdf_test.php` mantém uma falha na asserção de texto do PDF com IBPT indisponível; a mesma asserção falha no pacote original 0.8.1, neste ambiente. Essa falha não foi introduzida pela correção de escopo. O gate de fontes originais do empacotamento foi aprovado.
- 576 arquivos PHP aprovados na verificação de sintaxe, incluindo dependências distribuídas.

Resultados por suíte em `VALIDACAO_082.txt`. Não foram transmitidas notas nem acessados serviços fiscais externos.

## Atualização

Atualizar este pacote e o Multiempresa 1.2.6 na instalação principal. Na empresa interrompida, usar **Revisar e retomar**, revisar os módulos e confirmar. Os dados e as etapas concluídas são preservados.
