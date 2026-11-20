# Atualização para 0.4.3

## Correção e diagnóstico

Os pacotes anteriores atribuíam a todos os arquivos do ZIP a mesma data fixa: **10/11/2026 às 00:00:00**. O empacotador agora utiliza a data/hora UTC real de criação, capturada uma única vez por build e ajustada à precisão de dois segundos do formato ZIP. A estrutura do pacote, as exclusões e os modos dos arquivos foram mantidos.

Na inspeção autenticada do portal, **Definições → Módulos** apresentou a tela nativa com envio de pacotes e identificou a NFS-e como **0.4.2**, enquanto o código em execução ainda correspondia à **0.1.0**. A interface observada é da instalação principal; o código de referência do Multiempresa confirma que ela executa o PHP diretamente, sem a cópia privada usada nas empresas provisionadas.

A divergência é compatível com retenção de código antigo em cache de PHP, mas **a configuração e o estado do OPcache não foram verificados**. O hotfix corrige o timestamp repetido do pacote; sua suficiência para resolver a execução antiga depende da validação após a instalação. Não inclui reinício automático, limpeza de cache pelo módulo ou alteração no Multiempresa.

## Aplicar na instalação principal

1. Preserve um backup dos arquivos e do banco existentes.
2. Em **Definições → Módulos**, utilize **Upload Module** para enviar o pacote completo **0.4.3** pelo instalador nativo. Não desinstale a NFS-e para atualizar, pois a desinstalação remove suas tabelas.
3. Execute a atualização de banco se o Perfex apresentar essa ação. A migração 043 é vazia.
4. Reabra o CRM e confira **NFSE → Documentos**, o ícone do item principal e a guia NFS-e do cliente. Nas configurações, abra **Documentos Fiscais → NFS-e Nacional** e confira as quatro abas e a versão **0.4.3** nas verificações recolhidas.
5. Confirme que `admin/nfse_nacional/settings` redireciona para a seção nativa `admin/settings?group=nfse_nacional`. A versão exibida na lista de módulos, isoladamente, não confirma que o PHP novo está em execução.

Se o código antigo continuar em execução, a administração deve verificar o arquivo efetivamente carregado e o cache de PHP da hospedagem antes de repetir a instalação. A existência de OPcache sem revalidação automática precisa ser confirmada no servidor; não foi assumida como causa comprovada.

Em empresas provisionadas, continua aplicável a atualização aprovada da cópia privada pelo fluxo existente do Multiempresa, descrita em [ATUALIZACAO_042.md](ATUALIZACAO_042.md). O hotfix 0.4.3 não modifica esse módulo nem seu patch opcional.

## Escopo e validação

Regras fiscais, emissão, certificados, cadastros, views, JavaScript e CSS permanecem como na 0.4.2. O pacote altera o empacotador, os metadados de versão, acrescenta a migração vazia 043 e atualiza a documentação. As verificações locais constam em [TESTES.md](TESTES.md); a implantação e a confirmação visual da 0.4.3 no portal ainda estão pendentes.
