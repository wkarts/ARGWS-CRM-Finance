# DNS e HTTPS no módulo

Os parâmetros ficam em **Multiempresa → Configurações globais → Endereços personalizados**. Não é necessário criar arquivo INI/PHP ou variável de ambiente. A atualização mantém o token cifrado e as preferências existentes. Deixar o campo de token vazio conserva a credencial salva.

## Correspondência dos parâmetros

| Parâmetro solicitado | Campo ou comportamento no módulo |
| --- | --- |
| `CLOUDFLARE_API_BASE_URL` | API Cloudflare, preenchida internamente com `https://api.cloudflare.com/client/v4`. Somente a API oficial recebe o token. |
| `CLOUDFLARE_API_TOKEN` | Token da API; cifrado no armazenamento privado. Aceita a colagem com ou sem o prefixo Bearer. |
| `CLOUDFLARE_ZONE_ID` | ID da zona; permanece vinculado aos recursos criados, mesmo após mudar a zona padrão. |
| `CLOUDFLARE_ZONE_NAME` | Nome da zona, opcional; valida a relação entre zona e domínio base. |
| `CLOUDFLARE_DRY_RUN` | Simular alterações de DNS. A aplicação de endereços não cria registros nem modifica as rotas. |
| `CLOUDFLARE_TEMPORARY_RECORD_TYPE` | Tipo do registro: A, AAAA ou CNAME. |
| `CLOUDFLARE_TEMPORARY_RECORD_TARGET` | Destino padrão: IPv4 para A, IPv6 para AAAA, hostname para CNAME. |
| `CLOUDFLARE_TEMPORARY_RECORD_PROXIED` | Proxy Cloudflare. Com proxy, o TTL enviado é automático. |
| `CLOUDFLARE_MANAGED_WILDCARD_DNS` | Usar um wildcard compartilhado para novos endereços. |
| `CLOUDFLARE_MANAGED_WILDCARD_TARGET` | Destino do wildcard; em branco utiliza o destino padrão. |
| `CLOUDFLARE_CUSTOM_HOSTNAME_ORIGIN` | Origem dos hostnames personalizados, ao habilitar Solicitar HTTPS pela Cloudflare for SaaS. Em branco usa o fallback da conta. |
| `CLOUDFLARE_CUSTOM_HOSTNAME_CA` | Autoridade do certificado: Automática, Let's Encrypt, Google Trust Services, DigiCert ou SSL.com. A escolha explícita depende do plano. |

Esses nomes identificam a correspondência solicitada; o módulo não os lê do ambiente. Sem habilitar DNS personalizado, o identificador curto da empresa continua funcionando no endereço atual.

## Aplicação e validação

1. Preencha os parâmetros e utilize **Testar conexão Cloudflare**. O teste usa os campos atuais, preservando o token salvo quando seu campo estiver vazio. Não salva alterações, não cria registros e não afirma ter comprovado a permissão de escrita. Salve as configurações após o teste para que o provisionamento use os novos valores.
2. O provisionamento usa os parâmetros salvos. Uma organização já existente tem a ação **Aplicar DNS**, com revisão dos endereços afetados.
3. O módulo registra o ID e a propriedade dos recursos. Repetir uma aplicação com os mesmos valores reutiliza o registro, sem duplicação. Recursos de terceiros são preservados.
4. Um endereço gerenciado só passa a ser utilizado após HTTPS alcançar o ponto de verificação desta instalação. Enquanto DNS, certificado ou origem estiverem pendentes, a empresa permanece acessível pelo identificador curto.
5. O cron nativo acompanha certificados e endereços pendentes em lotes limitados. Um hostname indisponível não impede a verificação dos demais.

Com wildcard, `*.crm.exemplo.com` atende os nomes cadastrados sob esse domínio base. O módulo continua recusando hosts desconhecidos. O wildcard pertence à instalação e é compartilhado: excluir uma organização não remove esse recurso. A remoção integral do módulo apresenta o wildcard no inventário antes de permitir sua exclusão. Alterar seu destino pode afetar todas as organizações que o utilizam.

## Certificados e servidor de origem

Wildcard DNS e certificado wildcard são recursos diferentes. O módulo pode criar o registro DNS e solicitar/acompanhar certificados por hostname pela API Cloudflare for SaaS. Não instala um serviço ACME nem modifica CloudPanel, Nginx ou Apache. O servidor de origem precisa encaminhar os hosts à instalação Perfex; um CNAME sozinho não configura esse encaminhamento.

A autoridade **Automática** deixa a Cloudflare escolher o emissor compatível. Escolher explicitamente `lets_encrypt` envia essa preferência e mantém a resposta do provedor; não troca silenciosamente de autoridade quando a conta não permite essa escolha. A disponibilidade de Cloudflare for SaaS, os limites de hostnames e as opções de certificado pertencem à conta Cloudflare.

A configuração interna do módulo elimina arquivos externos de parâmetros. Ela não concede permissões à credencial nem altera os recursos contratados na conta ou as permissões do servidor de origem. Se o host não estiver pronto, o endereço atual continua disponível.

## Falhas e exclusão

Erros apresentam o HTTP e os códigos numéricos da Cloudflare, sem exibir o token. Um 403 na consulta de registros não comprova erro no tipo A, AAAA ou CNAME: ocorre antes da criação. O teste consulta o mesmo endpoint usado pelo provisionamento. Permissão de leitura de detalhes da zona não é obrigatória para um token que consiga consultar DNS.

Reservas cujo registro comprovadamente não foi criado podem ser removidas sem acesso ao provedor. Reservas antigas exigem a evidência registrada da consulta que falhou. Registros efetivamente criados só são apagados com confirmação de propriedade. Uma zona selecionada incorretamente depois não redireciona a exclusão para outra zona.

Depois de uma execução falhar, **Revisar novamente** atualiza o inventário e limpa o aceite anterior. A nova revisão não executa a exclusão automaticamente. Para remover o cadastro preservando recursos externos, desmarque a remoção de DNS na revisão.

## Referências e validação

- [Diagnóstico de tokens — Cloudflare](https://developers.cloudflare.com/fundamentals/api/troubleshooting/)
- [Registros wildcard — Cloudflare](https://developers.cloudflare.com/dns/manage-dns-records/reference/wildcard-dns-records/)
- [Planos Cloudflare for SaaS](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/)
- [Origens de hostnames personalizados](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/custom-origin/)
- [Criação de hostnames personalizados](https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/domain-support/create-custom-hostnames/)

As chamadas Cloudflare desta versão foram verificadas com respostas controladas. Não houve criação, alteração, exclusão ou emissão de certificado na conta do usuário. O log enviado após 1.2.1 confirma HTTP 403 e código 10000 durante GET de DNS. Isso não identifica sozinho a causa da recusa. Em 1.2.2, o teste usa os valores preenchidos e, se a consulta DNS for recusada, consulta também a verificação do token. Token confirmado ativo aponta para a necessidade de conferir o acesso DNS à zona informada. Uma verificação inconclusiva não é apresentada como prova de token inválido; tokens de conta podem não suportar o endpoint de verificação de usuário. Não há como conceder permissões à credencial ou comprovar o resultado no servidor sem acesso autorizado à conta.
