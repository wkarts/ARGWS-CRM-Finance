# Instalação e configuração pela interface

## Começar sem integrações

1. Instale o ZIP na tela nativa de Módulos do Perfex e ative Multiempresa. Em uma atualização, abra a administração com um administrador: as migrations do módulo executam automaticamente.
2. Abra **Multiempresa → Organizações** e cadastre a organização.
3. Em **Empresas → Nova empresa**, informe os dados básicos e escolha **Salvar cadastro**. Nenhum banco, tabela empresarial ou registro DNS é criado nessa operação.
4. Cadastros salvos podem ser consultados, editados e excluídos. Use **Provisionar** quando quiser preparar o CRM da empresa.

Não é necessário editar arquivos INI, PHP ou regras de PATH_INFO. O instalador prepara automaticamente o estado privado e detecta o PHP CLI. As configurações operacionais ficam nas telas do módulo. A instalação existente do Perfex e suas configurações são preservadas.

## Acesso pelo endereço atual

Cada empresa recebe automaticamente um identificador aleatório, inclusive a instalação principal. A URL de navegação passa a ser `https://seu-crm/<identificador>/admin/...`, preservando rotas e filtros nativos. O identificador não concede acesso: login, vínculo e permissões locais são verificados novamente.

O instalador insere um pequeno trecho identificado em `index.php`, antes do início do CRM, e um hook em `application/config/my_hooks.php`. Mantém backup privado, não regrava o conteúdo restante e remove somente seus trechos na desinstalação. Esses caminhos precisam ser graváveis pela aplicação; a integração utiliza as regras de URL amigável que o próprio Perfex já usa, sem editar Nginx/Apache, INI ou variáveis de ambiente.

A entrada antiga `modules/multiempresa/tenant.php?__pme_route=...` continua resolvendo links anteriores. Após abrir um link antigo, a navegação usa o endereço curto. Formulários antigos ainda abertos devem ser recarregados depois da atualização. Estilos, scripts e fontes compartilhados continuam usando seus caminhos físicos.

A principal mantém seu banco e seus módulos. Seu endereço curto não cria um novo banco, usuário administrador ou cópia da empresa.

## Preparar um ambiente

Em **Configurações globais → Banco de dados**, escolha:

- **Manual:** informe no wizard a conexão da aplicação com uma base existente e seu prefixo. As permissões e as estruturas afetadas são verificadas antes de alterar o destino.
- **Automático:** cadastre a conexão administrativa uma vez, incluindo root se aplicável. O módulo cria banco/usuário ou as tabelas do prefixo e uma credencial empresarial com permissões limitadas. A senha administrativa não é usada como credencial de operação da empresa.

Nomes automáticos longos recebem um sufixo estável para respeitar os limites das permissões MySQL. O destino já reservado não é renomeado durante uma retomada.

Escolha também banco exclusivo ou prefixo exclusivo em banco compartilhado. O banco compartilhado deve existir. Campos administrativos são exibidos somente no modo automático. Configurar a conexão global não inicia provisionamentos.

No wizard, revise destino, módulos e os dados da empresa antes de iniciar. Apenas as etapas selecionadas são executadas. O certificado A1 é opcional; ao enviar um certificado na implantação, sua validação passa a ser necessária para concluir essa execução. CNPJ, endereço e certificado também podem ser preenchidos depois.

## DNS e Cloudflare opcionais

Com as duas opções de endereços personalizados desativadas, o módulo usa o endereço atual da aplicação e não chama a Cloudflare. A etapa informa essa escolha, sem bloquear o banco ou exigir um domínio.

Para endereços personalizados, escolha uma alternativa na interface:

- **Administrar registros DNS pela Cloudflare:** cadastre token, zona, domínio base, padrões e destino. O módulo consulta, cria, atualiza e remove somente os registros associados ao seu inventário.
- **Usar domínios próprios com apontamento já existente:** habilita endereços preparados previamente, sem realizar chamadas à API DNS.

A origem HTTPS precisa estar apta a atender os nomes escolhidos e encaminhá-los à entrada empresarial. A API DNS não instala certificados nem administra o servidor web. Essa infraestrutura não é necessária para usar o endereço padrão. Se ela não estiver disponível, mantenha as opções de domínios próprios desativadas.

Domínio base, padrões, credenciais e destinos são globais. Cada organização tem um identificador; a empresa usa o endereço da organização ou um endereço individual, conforme a opção habilitada. Mudanças nos endereços já aplicados exigem a revisão da organização. Desativar uma opção global não apaga os registros já existentes: exclusão de recursos continua exigindo uma confirmação com inventário.

## Consultas cadastrais

CNPJ e CEP funcionam automaticamente após instalar ou atualizar. Informe um valor válido e aguarde a consulta; campos disponíveis são preenchidos sem sobrescrever alterações manuais. Serviços públicos alternativos, limites de tempo e normalização são internos. Não há tokens, provedores, ativação, arquivos ou variáveis de ambiente para parametrizar. Se nenhum serviço responder, uma mensagem simples permite continuar manualmente.

## Diagnóstico e execução

A seção **Diagnóstico e execução do servidor**, nas configurações globais, mostra PHP CLI, execução de processos, links simbólicos e armazenamento privado. Se o executável detectado não corresponder ao PHP da aplicação, selecione o caminho nessa tela. É possível cadastrar IPs exatos de proxies confiáveis para a terminação de HTTPS. As alterações são gravadas automaticamente na configuração privada; o usuário não edita arquivos.

Provisionar requer PHP 8.1+, as extensões indicadas no README, execução de processos e links simbólicos disponíveis ao PHP, além de acesso aos destinos MySQL selecionados. O cadastro sem provisionamento não depende de uma conexão administrativa MySQL nem de execução de um processo empresarial. O módulo não amplia as permissões do sistema operacional nem contorna restrições da hospedagem.

## Retomar uma execução interrompida

Na grid, abra **Logs** para consultar a etapa e a causa. Corrija os parâmetros na interface e use **Retomar**. Confira o impacto e confirme a operação. Etapas concluídas são preservadas quando podem ser reaproveitadas; recursos não são duplicados.

Execuções da versão 1.1.0 interrompidas por `dns_configuration` podem ser retomadas sem ativar DNS. A nova versão não exige DNS na validação de banco. Uma falha de conexão ou de permissões continua sendo diagnosticada separadamente; nenhum recurso é anunciado como pronto antes da verificação final.

Mantenha backup dos dados e do diretório privado, incluindo a chave mestra. A atualização preserva a chave e as configurações operacionais, retira parâmetros antigos de consultas e elimina registros já excluídos logicamente para liberar seus identificadores.

Na versão 1.1.2, os módulos externos são executados em cópias privadas por empresa. Arquivos gerados durante a ativação não alteram o pacote central. SQL de seed com prefixos literais identificáveis e inclusões legadas baseadas no diretório do módulo recebem adaptação somente na cópia privada. Alterações reais do pacote continuam exigindo revisão antes da retomada.

## Atualização 1.1.4 — compras

Substitua somente o módulo Multiempresa pelo pacote novo, mantendo seu armazenamento privado e a chave. Abra a administração e use **Revisar e retomar** na execução interrompida do `purchase`, conservando a seleção atual. A cópia privada recebe automaticamente a correção de prefixo; as etapas concluídas, as tabelas parciais e as opções já preenchidas permanecem. Não é necessário excluir a empresa, recriar a organização, editar o módulo de compras ou executar SQL.

## Atualização 1.1.3

Substitua o pacote e abra a administração com um administrador. As migrations do módulo são executadas automaticamente. A referência do núcleo instalado é sincronizada após validar o banco principal. Execuções antigas com `core_changed` ficam disponíveis para continuar, mantendo as etapas concluídas.

O módulo não exige preparar um modelo de banco, registrar releases nem executar backups manualmente para provisionar. Ele conserva um modelo inicial por execução e migra bases empresariais antigas com backup e recuperação automáticos. Não aplica downgrade nem modifica o banco da principal. Uma atualização incompleta do próprio Perfex aguarda a correspondência entre código e banco; problemas reais de rede, permissões ou código de terceiros permanecem diagnosticados.
