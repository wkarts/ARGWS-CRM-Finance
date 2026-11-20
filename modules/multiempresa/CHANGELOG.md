# 1.2.8 — Atualização automática de módulos e migrations empresariais

- Substituir um pacote na principal passa a atualizar as cópias privadas das empresas que já o possuem, antes do acesso ou pelo cron nativo. Não recria nem reinicia provisionamentos concluídos.
- Módulos instalados executam somente as migrations pendentes. Preserva ativação/desativação e situação ativa/suspensa da empresa, sem reexecutar hooks de instalação/ativação durante a atualização.
- Módulos somente disponíveis recebem novos arquivos e permanecem sem instalação ou ativação. Nenhum pacote novo é atribuído automaticamente.
- Backup cifrado, validação da propriedade da base, manutenção por empresa, bloqueio concorrente, restauração automática em falha e estado persistido para recuperar interrupções. Pacotes distintos migram em processos separados.
- Mudanças simultâneas de núcleo e módulos deixam de bloquear os workers por assinaturas antigas de pacotes não envolvidos na operação. O núcleo é migrado antes dos módulos.
- Atualizações sem migrations SQL não exigem arquivos artificiais; lacunas em sequências existentes e rebaixamentos são recusados com preservação da versão anterior.
- Falha de provisionamento por pacote alterado pode continuar na execução original após a correção, preservando etapas concluídas. Erros históricos não reaparecem como falha de uma execução já recuperada.
- Varredura do cron alterna as empresas para evitar que uma atualização demorada monopolize as próximas execuções. Diagnóstico técnico continua somente na principal.
- Migração 128 sem alteração estrutural ou de dados; estados internos de manutenção utilizam o armazenamento existente.

# 1.2.7 — Mensagens simples nas empresas

- Tela de módulos das empresas passa a orientar somente a ativação/desativação. Removidas explicações de preparação de estrutura, envio de pacotes, atualizações e administração da principal.
- Falha de alteração orienta tentar novamente e procurar o suporte. Nenhuma referência ao painel Multiempresa nessa mensagem.
- Erros de navegação nas empresas recebem página simples, sem exceções, nomes de tecnologias, caminhos, diagnóstico interno ou códigos técnicos. APIs e AJAX conservam seus códigos de resposta, com mensagens públicas simplificadas.
- Diagnósticos de falha ao alterar módulos são registrados na principal, inclusive quando a solicitação é recusada antes de iniciar a ativação. Detalhes administrativos permanecem nos logs autorizados.
- Comunicação de acesso a colaboradores nas empresas também deixa de mencionar a administração da principal e políticas internas do módulo. Regras de autorização continuam em vigor.
- Migração 127 sem alteração de dados ou estrutura.

# 1.2.6 — Teste de conexão e diagnóstico de ativação NFS-e

- Resultado do teste de conexão volta a ficar visível: carregando, sucesso e falha possuem mensagens e estados distintos. Alterar os dados limpa o resultado e impede que uma resposta atrasada confirme credenciais antigas.
- Campos de conexão organizados em linhas servidor/porta e banco/usuário, independentes da seleção de armazenamento. TLS aparece somente quando aplicável à conexão manual.
- Pré-validação traduz erros de autenticação, banco ausente, servidor indisponível e TLS sem expor credenciais ou mensagens SQL.
- Falha conhecida do NFS-e que rejeita prefixo vazio recebe diagnóstico específico em vez de apenas `tenant_failed`. A correção do instalador está no NFS-e Nacional 0.8.2; os dois pacotes devem ser atualizados na central.
- Migração 126 sem alteração de dados. As etapas concluídas permanecem preservadas na retomada revisada.

# 1.2.5 — Banco exclusivo sem prefixo de tabelas

- Novas empresas em banco exclusivo usam nomes nativos sem prefixo (`clients`, `staff`, `invoices`, `options`), tanto na conexão automática quanto na manual. O servidor ignora um prefixo informado para criar uma base exclusiva nova.
- Banco compartilhado mantém prefixo obrigatório e privilégios restritos por tabela. Prefixo vazio continua sendo recusado nesse modo.
- Contexto empresarial, administrador inicial, colaboradores, módulos, manutenção, backup, restauração e exclusão aceitam o prefixo vazio de uma base exclusiva.
- Restauração de snapshot sem prefixo adapta somente nomes de tabelas quando o destino exige prefixo, preservando colunas, valores e chaves estrangeiras. Backups legados com prefixo também podem ser restaurados em um banco exclusivo vazio sem prefixo.
- Assistente e detalhes exibem “Sem prefixo” no modo exclusivo; campo de prefixo permanece disponível para banco compartilhado e vínculo de instalações já existentes.
- Bases exclusivas já provisionadas e execuções parciais antigas conservam seus nomes físicos e continuam funcionando. Esta atualização não renomeia tabelas em uso nem recria bancos. Vincular uma instalação existente preserva seu prefixo original.
- Migração 125 sem alteração de dados. Proteções da instalação principal mantidas para escopos sem prefixo.

# 1.2.4 — Isolamento de colaboradores e exclusão de identidades

- Empresas sugeridas no cadastro de colaboradores limitadas à organização da identidade. Sem organização, somente a própria instalação. Validação de vínculo também no servidor, incluindo envio forjado e edição de identidade pertencente a outro grupo.
- Administração global permanece no painel central, sem criar funcionário local, elevar cargo empresarial ou conceder entrada automática. A opção antiga de acesso global irrestrito é aposentada.
- Usar/vincular o criador exige mesma organização. Cadastro com administrador próprio e criador desmarcado não cria vínculo nem cópia do criador. E-mail de outra organização é recusado antes da gravação nativa, sem fundir identidades.
- Exclusão remove identidades de autenticação exclusivas criadas pelo módulo, metadados de autenticação e referências de acesso. Identidades preexistentes da principal e usuários compartilhados explicitamente dentro do mesmo grupo são preservados no escopo remanescente.
- Migração 124 limpa identidades órfãs de exclusões anteriores e vínculos entre organizações, incluindo sessões, tickets e tokens. Religar a principal a um grupo não restaura vínculos removidos.
- Recriar nome, identificador e e-mail após exclusão integral gera empresa e identidade novas. URL anterior e permissões excluídas não são reaproveitadas.
- Revisão de exclusão inicia com dados, arquivos, backups e DNS próprios selecionados, mantendo inventário e confirmação antes da execução.

# 1.2.3 — Políticas de usuários, isolamento e organização do painel

- Configurações explícitas para acesso global do administrador da principal, administrador inicial, vínculo opcional do criador, acesso padrão de novos colaboradores e gestão local de colaboradores. Administradores globais acessam todas as empresas por padrão; demais usuários dependem de vínculos expressos. As políticas não removem vínculos existentes.
- Assistente permite criar administrador próprio (nome, e-mail e senha), sem copiar automaticamente o criador. Usar o criador ou vinculá-lo adicionalmente são escolhas explícitas. Senhas ficam cifradas no cadastro pendente e não retornam ao navegador. Identidades de colaboradores existentes não são fundidas silenciosamente pelo novo cadastro administrativo.
- Acesso global prepara o cadastro local necessário na primeira entrada, sem criar vínculo persistente em todas as empresas. O administrador local não recebe privilégios globais. Cadastros nativos sem campos extras também respeitam as políticas de acesso padrão.
- Alternância operacional limitada à organização atual e às empresas autorizadas. A principal sem organização não lista empresas. Validação no servidor e na utilização de tickets impede contorno por POST direto ou por mudança posterior da organização. A administração global permanece separada no painel central.
- Navegação das dez guias sem corte de títulos; filtros, ações e rodapés padronizados; configurações recolhíveis com aviso de alterações pendentes. Situação organizacional separada de DNS/HTTPS; ações de exclusão e reprovisionamento agrupadas.
- Logs, acessos, eventos, atualizações, backups e auditoria mostram nomes e seletores, com filtros e detalhes técnicos recolhidos. Busca de colaboradores dispensa digitação de IDs. API e webhooks têm listas e estados vazios identificados.
- Guia Módulos usa o mesmo editor de empresas e distingue não disponibilizado, disponível desativado e ativo. Disponibilizar não ativa; repetir uma solicitação antiga não desativa um módulo já ativado.
- Corrige a ordem de renderização do cabeçalho na tela nativa de módulos das empresas, usando o carregador de views do Perfex.
- Atualização automática 123, sem migração de dados empresariais nem alteração de autenticação de clientes. Domínio próprio continua opcional; a URL da empresa define o contexto do cliente.

# 1.2.2 — Logos/favicon com arquivos estáticos e diagnóstico Cloudflare

- Corrige URLs geradas pelo Settings, cabeçalho, tela de login e helper nativo de favicon. Usa uma rota interna sem extensão, processada pelo módulo quando o servidor intercepta PNG/SVG/ICO como arquivos estáticos e não encaminha arquivos inexistentes ao PHP.
- Reaproveita as imagens privadas já enviadas. Não requer mover arquivos, enviar novamente os logos, alterar permissões ou editar a configuração do servidor.
- Versão da URL baseada no conteúdo, inclusive quando o favicon mantém o mesmo nome; respostas revalidáveis e suporte a HEAD. Mantém isolamento empresarial, validação de caminhos, restrição de formatos e proteção SVG.
- Regressão reproduz o 404 da versão anterior com interceptação de imagens estáticas e testa as URLs extraídas do HTML nativo, os bytes, substituição de favicon, contexto público, domínio personalizado e bloqueio de caminhos inválidos.
- Testar conexão Cloudflare usa os campos preenchidos na tela sem salvá-los. Token vazio mantém o salvo. O diagnóstico diferencia token confirmado ativo de verificação inconclusiva quando a consulta DNS é recusada, sem expor credenciais nem criar registros.
- Não altera os módulos padrão, a escolha de ativação, bancos empresariais ou as preferências DNS existentes.

# 1.2.1 — Disponibilidade de módulos, imagens e DNS

- Lista única de módulos padrão nas configurações, pré-selecionada e editável no assistente. Desmarcar todos é respeitado. Salvar padrões não altera empresas existentes.
- Disponibilizar um módulo não o ativa. A escolha independente Ativar agora habilita somente a seleção explícita. A primeira ativação posterior prepara as tabelas automaticamente pela tela nativa ou pela central.
- Reativação/desativação de versões já instaladas não faz backup integral a cada troca nem reabre o provisionamento. Primeira instalação e migrations continuam protegidas por backup.
- Falha na primeira ativação manual de um pacote disponibilizado aciona recuperação pelo backup da empresa e mantém o provisionamento concluído; falha de recuperação é registrada para preservar a integridade.
- Reutiliza as cópias privadas aprovadas durante a navegação, eliminando hashes completos de todos os pacotes a cada página; operações de instalação continuam verificando os fontes.
- Corrige acesso a logo claro/escuro SVG e favicon empresarial; revalida imagens substituídas e serve imagens públicas mesmo durante a manutenção breve de um módulo. Ajusta a barra superior na principal e nas empresas.
- Libera o bloqueio da sessão administrativa antes das operações longas do módulo.
- Configurações Cloudflare internas para zona, simulação, wildcard, origem e autoridade de certificado. Normaliza o token e aceita A, AAAA e CNAME; exibe HTTP e códigos do provedor sem expor a credencial.
- Wildcard compartilhado com inventário e propriedade; remover uma organização preserva o registro compartilhado. A desinstalação integral inclui sua revisão e remoção explícita.
- Reservas de DNS comprovadamente não criadas podem ser removidas sem depender de uma API indisponível. Falhas de execução permitem Revisar novamente e gerar um plano válido, sem executar automaticamente a exclusão.
- HTTPS opcional por Cloudflare for SaaS, com acompanhamento de Custom Hostnames no cron. Roteamento de domínio integrado à entrada nativa; host desconhecido não abre a principal. Endereços gerenciados só são liberados após prova HTTPS da própria instalação.
- Mantém as configurações existentes na atualização. Nenhum arquivo externo de parâmetros ou variável de ambiente é exigido.

# 1.2.0 — Rotas curtas, colaboradores e módulos nativos

- Identificador aleatório permanente de 20 caracteres para cada empresa e para a principal, sem nomes de empresa ou organização no caminho padrão. A autenticação e as permissões continuam obrigatórias.
- Integração automática e reversível no front controller e no hook nativo. Links antigos continuam resolvidos; caminhos internos e filtros são preservados após o login.
- Módulos desativados continuam disponíveis. Administradores empresariais podem ativar/desativar os pacotes já aprovados na tela nativa, com POST e CSRF; instalar, remover e atualizar continuam exclusivos da principal.
- Campo de empresas permitidas no cadastro nativo de colaboradores. Vínculos são criados pelo e-mail, sem solicitar IDs técnicos nem transferir privilégios de administrador entre bases.
- Retorno à última empresa autorizada no login, alternância entre organizações mediante vínculo explícito e proteção da principal para identidades sem acesso a ela, inclusive quando Multiempresa está desativado.
- Mantém o acesso administrativo à principal quando Multiempresa é desativado, mesmo que o último acesso tenha sido empresarial.
- Limita automaticamente nomes de bancos gerados ao tamanho do privilégio MySQL escapado, evitando perda de acesso depois de recarregar as permissões.
- Exceções de execução empresarial passam a registrar rota e diagnóstico sanitizado nos logs do módulo.
- Preserva consultas automáticas, exclusão física, retomada de provisionamento, backups e as correções de prefixos da versão anterior.

# 1.1.4 — Prefixos do instalador e das migrations de compras

- Corrige o erro MySQL 1146 em `tblpurchase_option`: o instalador do `purchase` criava a tabela com `db_prefix()`, mas dez INSERTs usavam `tbl` fixo.
- Adapta identificadores conhecidos em SQL literal dentro de PHP, incluindo instaladores e migrations, além dos arquivos `.sql`. Valores de negócio, comentários e código de interpolação permanecem preservados.
- Atualiza automaticamente as cópias privadas criadas pelo adaptador anterior, mesmo quando o pacote original não mudou. Mantém os arquivos gerados pela empresa que não foram alterados na distribuição.
- A retomada conserva estrutura, dados e módulos já concluídos; não exige remover a empresa, alterar o pacote de compras ou executar SQL manual.
- Inclui reprodução da falha com o instalador real, recuperação sobre tabelas parciais, preservação de opções existentes, execução da migration 101 e instalação limpa em banco exclusivo.

# 1.1.3 — Sincronização do núcleo, recuperação automática e alinhamento

- Corrige `core_changed` causado por referência antiga do núcleo: sincronização interna após validar a versão do banco principal, sem cadastro manual de releases.
- Atualiza bases empresariais com migrations nativas, backup cifrado prévio e recuperação automática de DDL/dados em caso de falha. Retoma pelo cron nativo ou próximo acesso, preservando a instalação principal.
- Persiste o modelo de criação por empresa; mudanças posteriores na origem não interrompem a execução entre tabelas. Um modelo anterior é migrado automaticamente depois de concluído.
- Executa as migrations do módulo ao abrir a administração após substituir o pacote. Desbloqueia execuções antigas interrompidas por referência de núcleo desatualizada.
- Alinha rodapés e botões dos formulários, mantém o estilo nativo e organiza as operações manuais em área avançada.
- Apresenta permissões da API em português, com finalidade e descrição; integração externa continua opcional. Não mostra formulário de token com empresa vazia.
- Adiciona ícone vetorial padrão de empresa no seletor, sem depender de logo, favicon ou fonte de ícones do tema.
- Inclui regressões com migrations reais, falha após alteração de dados e DDL, recuperação, colunas geradas, bloqueio de downgrade e proteção da principal.

# 1.1.2 — Consultas internas, exclusão física e correções de provisionamento

- CNPJ/CEP automáticos, sem ativação ou parâmetros; serviços públicos com fallback, cache cifrado, limites internos e preservação de alterações manuais.
- Wizard com Cancelar, Voltar, Continuar, total de etapas e rodapé fora da rolagem; navegação pelas etapas visitadas e revisão de uma execução interrompida.
- Modal centralizado com estilo herdado do Perfex; progresso de 20 px, sem cantos arredondados, percentual correto e acessível.
- Cartão de empresa acima do perfil nativo; principal/demo vinculável a organização e alternância restrita às empresas autorizadas daquele escopo.
- Ativar/desativar todos os módulos na edição, preservando ações individuais e excluindo Multiempresa.
- Cópias privadas dos módulos e das extensões nativas por empresa. Corrige o seed literal do `ma` e evita que arquivos gerados por `fleet` alterem o pacote compartilhado; ajusta inclusões legadas do gateway na cópia privada.
- Retomada com revisão conjunta dos pacotes realmente alterados, mantendo etapas concluídas compatíveis e dados existentes.
- Remover empresa pela grid sem excluir a organização. Cadastros, vínculos e histórico associado são eliminados fisicamente; identificadores ficam disponíveis novamente.
- Migration 112 elimina exclusões lógicas antigas já concluídas, retira configurações obsoletas de consulta e amplia armazenamento de inventários.
- Desinstalação com escolha de preservar ou remover empresas/dados; conclusão pelo fluxo nativo, proteção da instalação principal e exclusão completa das tabelas/arquivos próprios no modo de remoção.

# 1.1.1 — Correções de implantação e interface

- Corrige `dns_configuration` na etapa de banco: DNS desativado usa o endereço atual da aplicação.
- Entrada padrão sem PATH_INFO; links e sessão empresarial mantêm o contexto antes da inicialização do CRM.
- Salvar cadastro sem banco, DNS ou provisionamento; editar e preparar o mesmo cadastro posteriormente.
- Wizard, edição e confirmação usam modais Bootstrap nativos, sem backdrop com blur ou paleta própria.
- Navegação, controles e tabelas consistentes entre todas as abas; campos opcionais agrupados e recolhíveis.
- Parâmetros de PHP CLI e proxies administráveis na interface; diagnóstico do ambiente.
- Campos de integração condicionais; consulta cadastral pode ser desativada; logs exibem nomes.
- Seleção de módulo instalado substitui digitação do nome de pasta na aba Módulos.
- Migration 111 preserva dados, chave mestra, certificados e configurações existentes.

# Histórico

## 1.1.0

- Cadastro completo de empresas, CNPJ/CEP com consulta e sugestões revisáveis; A1 com senha/arquivo cifrados e validade/titular.
- Configuração global de provedores, credenciais MySQL, isolamento, Cloudflare, domínio, padrões e destinos.
- Banco exclusivo automático, prefixos com usuário e GRANTs exatos; modo manual com validação de escopo e permissões.
- Grid de organizações e ampliação da grid de empresas; recursos/endereço, edição, logs e exclusão física por escopo confirmado.
- Selecionar/desmarcar todos os módulos. Multiempresa excluído da seleção, aprovação e runtime empresarial.
- Cadastro separado do estado de implantação; dependências SQL externas adiadas sem ligação à central, diário de bootstrap e retomada de falhas.
- Regra anterior de bloqueio substituída por reprovisionamento com plano de impacto, aceite expresso e execução identificada.
- DNS com propriedade registrada, operações rastreáveis e aplicação explícita de mudanças organizacionais.
- Troca de empresa na sidebar sem novo login, restrita aos vínculos da mesma organização, com contexto recarregado e sessão anterior revogada.
- Logs estruturados filtráveis, download JSONL, diagnóstico de banco/worker sem segredos e preservação do histórico após exclusões.
- Migrations sequenciais 105–110, nomenclatura neutra, documentação de implantação e testes de contratos/integração.

## 1.0.4

- Tela principal reformulada com grid, resumo de situações, filtros, pesquisa, ordenação e paginação no servidor.
- Assistente de cinco etapas: dados da empresa, conexão e destino, módulos, revisão e provisionamento com conclusão.
- Etapas persistentes, confirmação idempotente, bloqueio de reprovisionamento e quarentena de execução interrompida. O acesso só é liberado após a validação final.
- Edição de nome, idioma, fuso, situação e módulos sem recriar dados. Destino, organização e identificador permanecem fixos. Preferência de idioma gravada na opção nativa `active_language`.
- Remoção com confirmação por nome e permissão própria: encerra acessos, tokens e integrações, cancela rotinas pendentes e preserva banco, arquivos, backups e auditoria.
- Mantido o agendamento de backups pela grid. Ativação repetida de um módulo já ativo não repete sua instalação.
- Migration 104 e verificações HTTP da implantação, edição, paginação, isolamento, falhas, remoção e permissões nativas.

## 1.0.3

- Retirada a exigência de Perfex 3.4.1; mínimo nativo declarado como 3.2.0, sem lista de patches permitidos na série 3.2.x.
- Provisionamento baseado na estrutura e versão do banco nativo instalado, com valores iniciais limpos projetados nas colunas existentes. Dados empresariais, credenciais e ativações de módulos não são copiados.
- Mantidas as verificações de correspondência entre banco e núcleo, prefixo vazio, isolamento e estado de falha.
- Autor e endereço do desenvolvedor atualizados: WWSoft → Wallace Kleiton, https://t.me/wkartspro.
- Migration sequencial 103, testes de compatibilidade com fixtures e regressão da integração nativa.

## 1.0.2

Módulo nativo com painel Perfex, entrada empresarial interna e instalador compartilhado. Migrations sequenciais 100–102. Integração da identidade nativa, separação de bancos/prefixos, sessões, arquivos e permissões. Provisionamento, vínculo de instalações existentes, módulos por empresa, webhooks genéricos, API de leitura, backups e recuperação após DDL parcial. Sem aplicação administrativa externa no pacote.
