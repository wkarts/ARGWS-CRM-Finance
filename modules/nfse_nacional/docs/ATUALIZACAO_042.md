# Atualização para 0.4.2

## Navegação e apresentação

- Menu lateral **NFSE**, com ícone nativo, e um único submenu **Documentos**, sem ícone próprio. Esse submenu reúne notas emitidas, rascunhos e pendências.
- Configurações somente em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**, nas abas **Emitente**, **Emissão**, **Serviço e tributos** e **Certificado A1**. As verificações da instalação ficam recolhidas no final.
- Guia **NFS-e** do cliente com ícone pelo registro nativo do Perfex. Nenhum script da NFS-e reescreve a navegação ou os ícones da aplicação.
- Painéis, colunas, formulários e tabelas com as classes do Perfex. O CSS adicional fica limitado ao conteúdo do módulo; os antigos assets de reparo de navegação estão inertes.

Esta versão substitui a orientação de menu da 0.4.1. A migração 042 não altera tabelas nem dados fiscais.

## Instalação principal

1. Faça backup dos arquivos e do banco da instalação.
2. Substitua a pasta **`modules/nfse_nacional` completa** pelo conteúdo do pacote 0.4.2. Inclua PHP, views, assets, migrações e demais arquivos do módulo; atualizar apenas CSS ou JavaScript não aplica a correção.
3. Execute **Atualizar banco de dados** se o Perfex apresentar essa ação. Preserve o banco e a chave de criptografia existentes. Não desinstale o módulo para atualizar, pois a desinstalação elimina suas tabelas.
4. Se a hospedagem usa OPcache sem revalidação automática, siga seu procedimento normal para recarregar o PHP. Depois abra novamente a página administrativa.
5. Abra a seção de NFS-e nas Configurações, expanda as verificações da instalação e confira a versão em execução: **0.4.2**.

## Empresas do Multiempresa

O Multiempresa fornecido mantém uma cópia privada aprovada do PHP para cada empresa. Atualizar somente a pasta central não atualiza essa cópia.

Depois de instalar o pacote completo na administração principal, utilize o fluxo administrativo de atualização já disponível no Multiempresa: abra a empresa correta, revise a versão da NFS-e e execute **Atualizar módulo** com a confirmação de compatibilidade exigida por esse fluxo. Aguarde a conclusão antes de reabrir o CRM da empresa. Confira **0.4.2** nas verificações da NFS-e dentro dela.

A versão 0.4.2 **não acrescenta alterações ao Multiempresa**. Se a ação de atualização já foi disponibilizada, não reaplique o patch opcional da versão anterior. A referência desse recurso existente está em [tools/multiempresa-update/LEIA-ME.md](../tools/multiempresa-update/LEIA-ME.md). Se a instalação ainda não dispõe de um fluxo para atualizar módulos ativos, a administração deve disponibilizar a versão aprovada conforme o procedimento compatível com sua base; não substitua a atualização por desativar/ativar ou por editar arquivos privados, fingerprints ou aprovações manualmente.

## Conferência após a aplicação

Abra o Perfil do cliente, a guia NFS-e, Faturas, **NFSE → Documentos** e Configurações. Confira que o menu mantém o mesmo ícone nessas páginas, que Documentos não tem ícone próprio e que os ícones dos demais módulos permanecem os originais do tema. Nas configurações, confira as quatro abas e o diagnóstico recolhido.

A rota antiga `admin/nfse_nacional/settings`, acessada por um usuário autorizado, deve redirecionar para `admin/settings?group=nfse_nacional`. Se ainda aparecer a página autônoma antiga, confira a versão do PHP efetivamente utilizada pela empresa. Recarregar o navegador não substitui a atualização dessa cópia.

## Limites da verificação

As verificações locais estão documentadas em [TESTES.md](TESTES.md). O acesso pelo navegador ao portal informado redirecionou para a tela de login; não houve inspeção das views autenticadas, aplicação do pacote no servidor ou confirmação visual no tema ativo. O roteiro acima deve ser conferido na instalação após a atualização completa e não exige transmitir ou enviar documentos fiscais.
