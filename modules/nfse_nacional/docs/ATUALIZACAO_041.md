# Atualização para 0.4.1 — histórico

**Este documento registra a versão anterior. Para a versão atual, siga [ATUALIZACAO_042.md](ATUALIZACAO_042.md).** A escolha de um link principal único foi substituída por **NFSE → Documentos**, com ícone somente no item principal. O reparo de navegação por JavaScript descrito abaixo foi retirado integralmente na versão 0.4.2. Não use este roteiro histórico para conferir o menu atual.

## Resultado esperado

- **NFS-e Nacional** é um único item no menu lateral, com ícone e link direto para as notas. Não possui filhos “Notas e rascunhos” ou “Configurações”.
- A guia **NFS-e** do cliente possui o mesmo ícone enquanto se navega por Perfil, Contatos, Faturas e demais guias. Ícones dos outros módulos permanecem os originais do tema.
- As configurações ficam em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**. Não há atalho de configurações na listagem de notas.
- A configuração contém quatro abas, sem ícones decorativos: **Emitente**, **Emissão**, **Serviço e tributos**, **Certificado A1**. As verificações da instalação ficam recolhidas no final.
- Ao abrir essas verificações, a versão do módulo deve ser **0.4.1**.

## Falha corrigida

O reparo de navegação anterior resolvia o endereço `#` contra a página atual. Ao abrir NFS-e, menus como Vendas e Contabilidade acabavam identificados como links fiscais e recebiam o ícone incorreto. O mesmo problema podia ocorrer com abas que usavam um endereço genérico.

A versão 0.4.1 ignora endereços vazios, fragmentos e consultas sem caminho; reconhece somente o item principal de NFS-e e a guia fiscal do cliente. Ao converter o submenu antigo em um link, remove também o handler de expansão que poderia impedir o clique de navegar. A comparação de DOM confirma que os demais menus e seus handlers não são alterados.

## Atualizar a instalação principal

1. Substitua a pasta **`modules/nfse_nacional` completa** pelo conteúdo do ZIP 0.4.1. Atualizar apenas `assets/` não atualiza os registros de menu, o controller e as views.
2. Execute a atualização de banco do módulo se o Perfex apresentar essa ação. A migração 041 não altera tabelas nem dados fiscais.
3. Abra novamente a página administrativa. Caso a hospedagem use OPcache sem revalidação automática, utilize o procedimento normal de recarga de PHP da hospedagem.
4. Confira a versão em execução e o caminho das configurações indicado acima.

Não desinstale o módulo para atualizar: a desinstalação elimina suas tabelas. Documentos, rascunhos, certificados, numeração e padrões permanecem no banco existente.

## Atualizar a empresa do Multiempresa

O Multiempresa 1.2.4 fornecido mantém uma cópia aprovada de PHP para cada empresa. Os assets estáticos de versões antigas podem vir da instalação central. Assim, somente substituir o pacote central pode resultar em **PHP antigo e CSS/JavaScript novos**. As capturas com submenu antigo, configuração autônoma e duas abas não correspondem ao PHP desta entrega; esse diagnóstico não confirma, por si só, a configuração efetiva do servidor.

A cópia da empresa precisa receber a atualização pelo fluxo administrativo do Multiempresa. O módulo NFS-e não altera aprovações ou cópias privadas automaticamente.

Foi incluído um **patch opcional para a base Multiempresa 1.2.4 fornecida**, em `tools/multiempresa-update/`. Ele expõe **Atualizar módulo** na administração da empresa e resolve a revisão vinculada à release vigente dentro do fluxo de aprovação, manutenção, backup e migração já existente. A aplicação do patch é uma etapa da administração da instalação principal; não ocorre ao ativar a NFS-e.

Consulte o `LEIA-ME.md` desse diretório antes de aplicá-lo. O aplicador confere a versão e os hashes dos arquivos; deve recusar uma base diferente, em vez de sobrescrever alterações locais. Não aplique o patch à cópia privada de uma empresa.

Depois de atualizar o código central e, quando necessário, disponibilizar a ação pelo patch:

1. Na administração central do **Multiempresa**, abra a empresa correta e a guia **Módulos**.
2. Revise a versão nova e marque a confirmação de compatibilidade existente na tela.
3. Em **NFS-e Nacional**, clique em **Atualizar módulo** e aguarde o retorno.
4. Reabra o CRM dessa empresa e confirme **0.4.1** nas verificações da instalação da NFS-e.

Não use Desativar/Ativar como substituto dessa atualização. Também não altere fingerprints, tabelas de aprovação ou arquivos privados manualmente. Se a migração falhar, o fluxo existente pode manter a empresa em manutenção com o backup disponível; a administração deve tratar o erro, sem liberar uma instalação incompleta.

## Conferência objetiva da versão aplicada

A rota antiga `admin/nfse_nacional/settings`, quando acessada por um usuário autorizado, deve redirecionar a `admin/settings?group=nfse_nacional`. Se ela continuar exibindo a página autônoma antiga, a empresa ainda não está executando o controller desta versão. Recarregar o navegador não atualiza essa cópia de PHP.

Depois da aplicação completa, conferir Perfil do cliente, guia NFS-e, Faturas, Notas e Configurações. O menu principal e a guia fiscal têm ícone; os ícones de outros módulos permanecem iguais entre essas páginas. O roteiro não exige transmissão nem envio de documento fiscal.
