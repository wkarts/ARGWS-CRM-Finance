# Multiempresa — módulo nativo para Perfex CRM

Versão **1.2.8**. Autor: **WWSoft → Wallace Kleiton** — https://t.me/wkartspro. Mínimo declarado **Perfex 3.2.0**, sem trava de patch na série **3.2.x**. O ZIP contém somente `multiempresa/`, para instalar em `modules/multiempresa/`.

A administração utiliza login, funcionários, permissões, menus e formulários do Perfex. O módulo não distribui o núcleo do CRM. Para reconhecer os endereços curtos antes de iniciar o Perfex, instala automaticamente um trecho identificado em `index.php` e um hook em `application/config/my_hooks.php`, com backup privado e remoção reversível. O restante desses arquivos e os arquivos de negócio são preservados. Extensões nativas gravadas por módulos ficam em cópias privadas empresariais. A entrada `tenant.php` estabelece o contexto da empresa antes de carregar o núcleo compartilhado. O módulo Multiempresa pertence exclusivamente à central: não entra na lista de seleção, não é instalado nem ativado nas empresas.

## Tabelas nos bancos empresariais

Novos bancos exclusivos são provisionados **sem prefixo nas tabelas**. Em banco compartilhado, o prefixo exclusivo de cada empresa permanece obrigatório. A criação automática de banco e credencial continua disponível; o administrador escolhe os módulos a disponibilizar e quais ativar.

Empresas já existentes mantêm seus nomes físicos para preservar dados e acessos. Para converter uma base antiga, utilize a restauração já disponível em **outro banco exclusivo vazio**, deixando o prefixo de destino em branco. A origem é preservada e a empresa restaurada permanece suspensa para conferência antes da liberação. Esta versão não renomeia tabelas automaticamente durante a atualização.

## Instalar ou atualizar

1. Preserve o diretório privado e sua chave mestra ao substituir o pacote. O módulo cria seus backups de segurança antes de migrar bases empresariais.
2. Envie o ZIP pela tela nativa de Módulos, ou substitua somente `modules/multiempresa/`.
3. Na primeira instalação, ative o módulo. Nas atualizações, abra uma tela administrativa com um administrador: as migrations **100–128** do módulo são executadas automaticamente. Não é necessário registrar uma versão, preparar um esquema, editar arquivos ou executar SQL.
4. Em **Multiempresa → Configurações globais**, habilite somente os recursos desejados: banco automático e domínios/DNS são opcionais. CNPJ e CEP funcionam automaticamente, sem parametrização.
5. O acesso padrão usa `https://seu-crm/<identificador-aleatorio>/admin`, inclusive para a principal. Os identificadores são gerados automaticamente e não revelam nomes de empresas ou organizações. Nenhuma edição manual de INI ou PHP é necessária. Consulte [IMPLANTACAO.md](docs/IMPLANTACAO.md).
6. Cadastre a organização na grid **Organizações**. Em **Empresas → Nova empresa**, informe identificação, cadastro, configurações específicas e módulos; use **Salvar cadastro** para provisionar depois, ou revise e inicie o provisionamento.
7. Acompanhe banco, estrutura, configurações, módulos, certificado quando solicitado, DNS e verificação final. O acesso só é liberado após todas as etapas obrigatórias.

Não execute Composer ou Node para instalar. O autoloader acompanha o módulo. PHP CLI é usado internamente pelos processos isolados; a administração acontece pelas telas.

## Módulos padrão e ativação

Em **Configurações globais → Módulos padrão para provisionar**, salve uma única lista. Ela aparece pré-selecionada em cada nova empresa; o assistente permite manter, acrescentar, retirar ou desmarcar todos os módulos. A preferência não modifica empresas existentes.

Selecionar um módulo disponibiliza sua cópia privada, **desativada**. A opção independente **Ativar agora** determina quais serão instalados e habilitados durante o provisionamento. Sem essa escolha, a primeira ativação pode ser feita depois na tela nativa **Módulos** da empresa ou no painel Multiempresa; o instalador e as tabelas são executados automaticamente nessa ativação.

Ativar/desativar novamente a mesma versão já instalada usa os hooks nativos, sem backup completo a cada troca e sem reiniciar o provisionamento. Primeira instalação e migrations mantêm o backup de segurança. Pacotes disponíveis permanecem visíveis quando desativados; envio, remoção e atualização de código ficam na principal.

## Usuários e políticas administrativas

Em **Configurações globais → Usuários e políticas administrativas**, defina:

| Política | Padrão |
| --- | --- |
| Administração global | Gerencia todos os grupos pela central; não cria acesso operacional |
| Administrador inicial sugerido | Criar administrador próprio da nova empresa |
| Vínculo adicional do criador | Desmarcado; pode ser pré-selecionado e revisto no assistente |
| Acesso à principal para novo colaborador da central | Desmarcado |
| Acesso de novo colaborador criado dentro de empresa | Somente a empresa atual, pré-selecionada |
| Gestão local de colaboradores | Permitida aos administradores locais, somente na própria empresa |

O assistente solicita nome, e-mail e senha do novo administrador. Alternativamente, pode usar o usuário criador por escolha explícita. Vincular o criador adicionalmente é opcional e exige que a principal e a empresa pertençam à mesma organização ativa. Sem organização ou em grupo distinto, o assistente exige um administrador próprio. O administrador próprio recebe acesso à sua empresa, sem cargo global ou acesso à principal. Para usar uma identidade de colaborador já existente, faça a concessão explícita no cadastro de colaboradores; o novo administrador não substitui senhas de identidades existentes. Instalações vinculadas e reprovisionamentos de bases já criadas preservam os administradores existentes.

A administração central não cria funcionários locais durante a entrada nem dispensa vínculo operacional. A antiga política de acesso global irrestrito foi descontinuada. A migração 124 remove vínculos incompatíveis, revoga as sessões/tokens correspondentes e limpa identidades de autenticação criadas pelo módulo cuja empresa já foi excluída. Alterar a organização da principal remove acessos incompatíveis; voltar à organização anterior não restaura permissões automaticamente.

Em **Definições → Colaboradores → Permissões → Acesso às empresas**, o administrador principal seleciona os vínculos autorizados. Novos funcionários locais criados por essa concessão não recebem cargo administrativo; configure as permissões de trabalho em cada empresa. O administrador local gerencia somente sua empresa quando essa política estiver permitida. Outros colaboradores não herdam os acessos de quem os cadastrou, nem de outras empresas da organização. Os padrões também são aplicados aos cadastros nativos que não enviam os campos adicionais do módulo.

O seletor operacional mostra somente empresas autorizadas da **mesma organização atual**. A principal só participa quando vinculada à mesma organização; sem organização, não lista empresas secundárias. A regra é aplicada também no servidor. Vínculos entre organizações distintas são recusados no formulário, no servidor, no login, na API e no acesso direto pela URL. Empresa sem organização não compartilha colaboradores com outras empresas.

O login de colaboradores utiliza a identidade central e retorna à última empresa ainda autorizada. Administradores globais mantêm acesso à principal. O login de clientes permanece restrito à empresa definida pelo domínio ou identificador da URL: não procura e-mail entre todas as bases. Domínios próprios são opcionais.

## Exclusão e recriação

A exclusão confirmada remove fisicamente cadastro, vínculos, rotas, sessões, tokens, preferências e histórico da empresa. A tela inicia com banco/tabelas, arquivos, backups e DNS próprios selecionados para exclusão; o inventário e a confirmação continuam obrigatórios. Recursos compartilhados pertencentes a outras empresas não são apagados.

Identidades centrais de autenticação criadas exclusivamente para a empresa também são removidas, liberando o e-mail para um novo cadastro. Colaboradores preexistentes da principal são preservados. Uma identidade explicitamente compartilhada com outra empresa da mesma organização permanece somente nessa empresa; excluída a última empresa proprietária, a identidade exclusiva é removida. Recriar nome/identificador gera novos IDs, endereço e permissões, sem recuperar vínculos antigos.

Cadastros locais antigos não são eliminados em empresas que continuam ativas, pois podem estar referenciados em documentos. O vínculo incompatível é removido e não permite autenticação. A exclusão integral da empresa remove essas tabelas no escopo confirmado.

## Organização do painel

As dez guias usam navegação com quebra de linha e botões alinhados. Configurações estão em seções recolhíveis, com indicação de alterações pendentes. Organizações exibem situação cadastral e DNS/HTTPS separadamente. A guia Módulos utiliza o editor de empresas e diferencia não disponibilizado, disponível desativado e ativo; disponibilizar não ativa o pacote.

Logs, usuários/acessos, eventos, backups e auditoria utilizam nomes e seletores em vez de exigir IDs. Filtros de período, empresa, situação ou responsável aparecem conforme a área; IDs e informações técnicas permanecem nos detalhes. Ações destrutivas de empresas ficam em Mais ações, mantendo a revisão de impacto existente.

## Banco e atualizações automáticas

A versão do Perfex instalado é sincronizada com o catálogo interno depois de conferir código e banco da principal. A referência antiga que causava `core_changed` deixa de bloquear o provisionamento. Bases empresariais de versões anteriores recebem backup criptografado e migrations nativas antes do acesso, durante o provisionamento ou pelo cron nativo. Em falha de migration, o módulo restaura automaticamente o backup da própria empresa e tenta novamente em execução posterior. O banco principal não é migrado nem restaurado por esse processo.

Os módulos **já disponibilizados** também são atualizados automaticamente após a substituição do pacote na principal. A verificação ocorre antes do próximo acesso à empresa e pelo cron nativo do CRM. Alterações de versão são reconhecidas imediatamente; substituições de arquivos mantendo a mesma versão usam uma janela de cache de até 30 segundos. A cópia privada recebe os arquivos atuais; módulos instalados executam somente as migrations nativas pendentes. Não é necessário reprovisionar, reinstalar ou revisar novamente as empresas por causa da atualização de um pacote.

Módulos ativos continuam ativos; instalados e desativados continuam desativados. Módulos apenas disponíveis recebem o código novo sem executar instalador, migrations ou ativação antes da escolha do administrador. Pacotes não atribuídos à empresa não são acrescentados. Atualizar não reexecuta o hook de ativação, não recria o administrador e não reinicia o assistente concluído. O processo recusa rebaixamentos automáticos.

Antes de alterar uma empresa, a manutenção aguarda as requisições em curso e cria um backup cifrado. Cada módulo migra em processo isolado, sem colisão entre classes de versões iguais de módulos diferentes. Falhas restauram banco, arquivos e catálogo anteriores; a tentativa é retomada automaticamente em execução posterior. Interrupções após a conclusão do worker são finalizadas sem repetir as migrations. Diagnósticos técnicos ficam nos logs da principal. Uma migration incorreta ou ausente deve ser corrigida no pacote de origem; o módulo não inventa SQL para substituí-la. Provisionamentos antigos interrompidos por pacote alterado também podem continuar na mesma execução quando o pacote é corrigido, preservando as etapas concluídas.

A estrutura inicial é registrada em um modelo cifrado por empresa antes da criação das tabelas. Assim, uma atualização da origem não muda a implantação pela metade. Ao concluir um modelo antigo, as migrations levam o destino à versão atual. Falhas antigas `core_changed` podem continuar sem um plano manual de reprovisionamento.

Restauração escolhida pelo administrador e publicação de um diretório de versão preparado continuam disponíveis em **Atualizações e backups → Operações manuais**. Atualizar os arquivos do Perfex principal continua sendo uma operação nativa do próprio Perfex. Credenciais, permissões do servidor e falhas em código de terceiros precisam ser válidas; o módulo não altera os privilégios do servidor sem a conexão administrativa autorizada.

## Funcionalidades

| Área | Comportamento |
| --- | --- |
| Organizações e empresas | Grids com pesquisa, filtros, ordenação e paginação no servidor; edição, recursos, endereços, logs e exclusão com impacto confirmado |
| Cadastro | CNPJ com máscara e dígitos verificadores, razão social, nome fantasia, inscrições, contato, endereço, CNAE e demais campos correspondentes |
| Consultas | Automáticas ao concluir um CNPJ/CEP válido; serviços públicos com fallback interno, sem tokens, ativação, URLs ou mapeamentos configuráveis |
| Revisão de sugestões | Campos vazios podem ser preenchidos automaticamente; campos existentes ou editados só são substituídos mediante escolha explícita |
| A1 | Importação PFX/P12, verificação de chave privada e validade, titular e vencimento; arquivo e senha cifrados por empresa; sem retorno dos segredos ao navegador |
| MySQL automático | Conexão administrativa global, inclusive root, cria banco exclusivo ou tabelas em banco compartilhado; cria credencial de aplicação própria e permissões delimitadas |
| MySQL manual | Banco existente, conexão e prefixo específicos; valida escopo e permissões antes do DDL; suporta vínculo de uma instalação existente compatível |
| Módulos | Tela nativa permite ativar/desativar módulos já disponibilizados, mantendo os desativados na lista; instalação, atualização e remoção só na principal. Selecionar/desmarcar todos na implantação e ativar/desativar todos na edição, além das ações individuais; cópias privadas por empresa; Multiempresa excluído também no backend |
| Cloudflare | A/AAAA/CNAME, wildcard compartilhado, simulação, teste da conexão preenchida, diagnóstico HTTP/códigos e HTTPS opcional via Custom Hostnames; propriedade registrada, sem assumir recursos de terceiros |
| Endereços | Endereço atual da aplicação por padrão, sem DNS adicional; opcionalmente hosts próprios de organização/empresa e destino comum ou específico |
| Implantação | Cadastro independente de provisionamento, com preparação posterior no mesmo registro; estado persistente, chave idempotente, etapas e diagnóstico; cadastro pendente não é confundido com empresa provisionada |
| Retomada | Voltar às etapas visitadas, revisar cadastro/módulos e retomar somente o necessário; etapas concluídas compatíveis são preservadas |
| Reprovisionamento | Permitido após revisão, aceite e nome digitado; reaplica preferências/módulos/DNS e reutiliza dados, sem limpar a base |
| Exclusão | Exclusão física do cadastro, vínculos e histórico associado, com identificador reutilizável; dados, arquivos, backups e DNS seguem o escopo confirmado; excluir empresa preserva a organização |
| Troca de empresa | Cartão acima do perfil nativo, com empresa atual; principal/demo pode integrar uma organização; somente empresas autorizadas dessa organização; sessão anterior revogada |
| Desinstalação | Preservar ambientes para reinstalar ou remover cadastros, recursos próprios e tabelas do módulo; instalação principal sempre protegida |
| Logs | Organização, empresa, ator, execução, etapa, resultado, duração e diagnóstico técnico; filtros e download JSONL; segredos omitidos |
| Recursos preservados | Autenticação nativa, API de leitura, webhooks genéricos, fila, cron, módulos externos, backups e plano de atualização/recuperação |

## Logos e endereços personalizados

Logo claro, logo escuro e favicon são enviados pelas configurações nativas da própria empresa. As imagens permanecem isoladas. As URLs das imagens são geradas por uma rota interna sem extensão, evitando o 404 de regras de arquivos estáticos do CloudPanel/Nginx sobre diretórios empresariais virtuais. Isso também vale para imagens já enviadas, sem novo upload ou ajuste manual do servidor. Uma versão baseada no conteúdo atualiza o endereço quando o favicon é substituído. Logos SVG são servidos com política de segurança e as imagens são revalidadas após substituição. A execução normal reutiliza os módulos privados preparados, evitando recalcular todos os pacotes em cada página.

Todos os parâmetros Cloudflare ficam nas configurações do módulo. Um wildcard pode atender vários endereços; sua exclusão só ocorre na remoção integral confirmada, pois é compartilhado. A opção HTTPS usa a API Cloudflare for SaaS e acompanha o certificado pelo cron nativo. Um endereço gerenciado só passa a ser usado quando HTTPS alcança esta instalação. O endereço curto continua disponível durante a validação. Consulte [DNS.md](docs/DNS.md) para campos, permissões e limites da conta/origem.

## Requisitos

- Perfex 3.2.0 ou superior com os recursos nativos verificados pelo adaptador; PHP **8.1+** e MySQL/MariaDB com InnoDB.
- Extensões `pdo_mysql`, `mysqli`, `sodium`, `mbstring`, `zip`, `curl` e `openssl`, além das exigidas pelo CRM.
- Para executar ambientes: PHP CLI compatível, `proc_open`, criação de symlinks e diretório gravável **fora do DocumentRoot**. O instalador rejeita dados privados na raiz pública.
- Prefixo nativo e prefixos empresariais de até 16 caracteres. Os nomes gerados devem respeitar os limites do MySQL e do DNS.
- HTTPS, saída HTTPS para as consultas públicas e integrações escolhidas e acesso MySQL aos destinos configurados.
- O instalador precisa gravar os dois pontos de integração descritos acima. A entrada curta utiliza o encaminhamento ao `index.php` já usado pelas rotas nativas do Perfex. A entrada PHP antiga continua aceita para links existentes; não é a URL de navegação gerada. Domínios personalizados permanecem opcionais.

Em modo automático por prefixo, o banco compartilhado precisa existir. As permissões da credencial empresarial são dadas às tabelas exatas. Módulos que calculam nomes de tabelas dinamicamente devem declarar esses nomes no manifesto de integração; veja [EXTENSOES.md](docs/EXTENSOES.md). Módulos de terceiros continuam sujeitos a homologação.

A estrutura vem do banco nativo instalado, com sua versão real de migrations. Somente valores iniciais limpos são carregados: clientes, usuários, tokens e configurações da central não são clonados. Chaves estrangeiras adicionadas por módulos externos são separadas da criação inicial e aplicadas dentro do novo prefixo quando suas tabelas estiverem disponíveis. Nenhuma referência é mantida para dados empresariais da central.

## Migrations

| Número | Responsabilidade |
| --- | --- |
| 100 | Organizações, empresas, identidades e vínculos |
| 101 | Sessões, tickets, API, eventos e auditoria |
| 102 | Versões, manutenção, módulos, jobs e recuperação |
| 103 | Adaptação ao esquema nativo e compatibilidade de versão |
| 104 | Andamento persistente do wizard |
| 105 | Cadastro empresarial e certificados cifrados |
| 106 | Inventário de recursos e planos de impacto |
| 107 | Logs estruturados, assinados e indexados |
| 108 | Configuração e hosts de organizações |
| 109 | Organização da identidade e tickets de troca de empresa |
| 110 | Consolidação da instalação atual e exclusão de aprovações empresariais do módulo central |
| 111 | Atualização idempotente para cadastro separado, DNS opcional, entrada sem PATH_INFO e interface nativa; preserva as tabelas e configurações existentes |
| 112 | Consultas internas e cache cifrado, retirada de parâmetros antigos, exclusão física de tombstones concluídos e suporte ao inventário de desinstalação |
| 121 | Preferências internas, disponibilidade separada da ativação, DNS/HTTPS e atualização das integrações; preserva configurações e dados existentes |
| 122 | Rota interna de logos/favicon compatível com regras de arquivos estáticos e teste Cloudflare com parâmetros preenchidos; preserva imagens e configurações |

As migrations de atualização eliminam cadastros já excluídos logicamente por versões anteriores e libera seus identificadores; não remove os recursos externos que foram preservados naquela exclusão. Cadastros ativos e pendentes permanecem. Empresas interrompidas podem ser revistas e retomadas. Estruturas antigas parciais sem prova de propriedade exigem revisão do destino; a atualização não assume tabelas arbitrárias. Entradas nativas antigas de Multiempresa nas empresas são removidas ao inicializar seu contexto; o código do módulo central não é disponibilizado como módulo empresarial.

## Estrutura e desenvolvimento

`controllers/`, `views/`, `assets/` e `language/` integram a administração nativa. `libraries/Application/` contém os serviços, `Infrastructure/` os mecanismos de persistência e proteção, `Perfex/` o adaptador e runtime, e `Contracts/` os pontos de extensão. `config/extensions.php` registra implementações próprias. `resources/perfex-341.sql` é somente uma fixture de teste; não é o esquema usado pelo provisionamento em produção.

```sh
php scripts/version.php --check
php tests/unit.php
php scripts/package.php /destino/multiempresa-module-1.2.2.zip
```

O empacotador recusa sobrescrever um ZIP existente e exclui caches de desenvolvimento. `scripts/version.php --next` cria a próxima migration sequencial sem substituir arquivos publicados.

Leia [OPERACAO.md](docs/OPERACAO.md), [IMPLANTACAO.md](docs/IMPLANTACAO.md), [EXTENSOES.md](docs/EXTENSOES.md) e [VALIDACAO.md](docs/VALIDACAO.md). A integração nativa executada usa a distribuição Perfex 3.4.1 fornecida. A série 3.2.x foi verificada por fixtures de esquema, não por distribuições completas. Cloudflare tem testes com transporte controlado. Consultas públicas têm contratos, testes de fallback e sondagens HTTP; consulte os limites em VALIDACAO.md. Nenhuma credencial comercial é necessária para CNPJ/CEP.

A desativação bloqueia o acesso multiempresa e preserva os recursos. Exclusão física ocorre somente pelas operações confirmadas. A configuração privada e sua chave mestra são necessárias para abrir credenciais, certificados e backups cifrados.
