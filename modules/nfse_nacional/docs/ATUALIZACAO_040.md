# Histórico da atualização 0.4.0

Este roteiro foi substituído por [ATUALIZACAO_041.md](ATUALIZACAO_041.md).

A versão 0.4.0 introduziu as consultas cadastrais e o transporte de assets da cópia executada pela empresa. A versão 0.4.1 corrige a identificação de menus e a apresentação das configurações.

## Multiempresa 1.2.4

A interface original não apresenta Atualizar para módulos ativos. A reaplicação da versão precisa ocorrer no fluxo administrativo da instalação principal, com revisão explícita, autorização e CSRF válidos. O patch opcional da 0.4.1 disponibiliza essa ação e mantém manutenção, backup, revisão vigente e migração dentro da mesma operação de aprovação.

**O procedimento anterior de chamadas manuais separadas para revisar a release e aplicar o módulo foi retirado.** Publicar a revisão antes de entrar em manutenção cria uma janela em que a empresa pode carregar a cópia nova antes da migração. Use o procedimento integrado documentado na versão 0.4.1; não altere as tabelas de revisão, os fingerprints ou a cópia privada manualmente.

Atualize a pasta completa do módulo, sem desinstalá-lo, e confira a versão efetivamente executada pela empresa. A migração 040 não altera tabelas fiscais.
