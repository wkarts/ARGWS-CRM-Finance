# Autenticação da API — versão 0.8.3

Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional → Autenticação API**, configure um ambiente ativo e um método. Há um único arquivo A1 local (.pfx/.p12) com senha e um único cadastro protegido de usuário/senha para os dois ambientes. A troca de ambiente não recadastra credenciais.

O A1 local configurado tem prioridade. Na ausência dele, a integração aproveita o certificado autorizado da empresa. Os documentos históricos preservam seu ambiente fiscal de origem; trocar o ambiente padrão não reescreve XMLs, DPS ou documentos anteriores.

## Métodos efetivamente utilizados

| Operações implementadas | Produção restrita | Produção |
| --- | --- | --- |
| Emissão, consulta da DPS e eventos SEFIN | Certificado na conexão e assinatura quando aplicável | Certificado na conexão e assinatura quando aplicável |
| Distribuição de documentos ADN | Certificado e autorização do contribuinte | Certificado e autorização do contribuinte |
| Usuário/senha do portal | Sem fluxo documentado implementado para essas APIs | Sem fluxo documentado implementado para essas APIs |

Selecionar **Usuário e senha — indisponível na API nacional** informa a limitação e impede as chamadas incompatíveis. O cadastro protegido não é apresentado como uma autenticação funcional. Não há endpoint de login inventado, emissão simulada, obtenção fictícia de token ou troca silenciosa para A1.

## Preservação das configurações anteriores

A migração 083 mantém os certificados e os logins antigos por ambiente no material protegido. Mantém também uma cópia da configuração anterior dos métodos e do A1 local.

- Certificados idênticos ou um único certificado podem ser aproveitados no cadastro compartilhado.
- Certificados diferentes exigem escolha explícita na configuração, ou envio de um novo A1. Enquanto houver conflito, a transmissão informa a pendência.
- Credenciais de login diferentes não são combinadas silenciosamente. A tela permite aproveitar um dos cadastros anteriores.
- O método do ambiente que estava ativo é preservado como método compartilhado. Se os métodos antigos eram diferentes, a tela explica a decisão e mantém o histórico protegido.
- Campos vazios preservam senhas existentes. Alterações de usuário exigem a senha correspondente.
- O algoritmo e o contexto de derivação das chaves existentes permanecem intactos.

## Operações em segundo plano

O cron precisa acessar um certificado autorizado sem depender de uma sessão interativa. O A1 local atende a essa condição. Um provedor de certificado da empresa também pode atendê-la pelo contrato opcional existente. O módulo não copia certificados de outro sistema sem autorização nem cria identidades ou bancos para o provisionador.

Caso a integração da empresa disponibilize o A1 somente em sessão, configure o A1 local ou habilite o acesso em segundo plano no provedor. A fila registra falha de disponibilidade; não transmite com outro emitente nem troca o método selecionado.

## Documentação oficial consultada em 13/11/2026

- [Documentação atual — produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual)
- [Documentação de produção restrita](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita)
- [Manual do contribuinte — Emissor Público API](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf)
- [Guia de utilização das APIs ADN](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-apis-adn-sistema-nacional-nfse.pdf)
- [Catálogo oficial dos ambientes](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao)

Os Swaggers dos dois ambientes não puderam ser abertos diretamente nesta execução. As chamadas autenticadas reais também não foram executadas. Os testes locais usam certificados e respostas sintéticos, sem representar homologação fiscal.
