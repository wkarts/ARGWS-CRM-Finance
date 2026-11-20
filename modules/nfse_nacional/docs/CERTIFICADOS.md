# Certificados A1 — integração e compatibilidade da versão 0.4.5

## Comportamento na aplicação

Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional → Certificado A1**, as configurações mostram disponibilidade, titular e validade do A1. O bloco **Certificado A1 local** mantém os campos de arquivo e senha sempre acessíveis para instalar ou substituir o certificado do emitente dentro do módulo. A opção **Usar certificado A1 local** controla somente sua prioridade. Não existe seletor de origem nem configuração de banco, provedor, URL ou token de certificado.

Para importar, configure o CPF/CNPJ do emitente, selecione o arquivo `.pfx`/`.p12` e sua senha, e salve as configurações. O arquivo é opcional quando não há substituição; senhas vazias são aceitas para arquivos que assim foram exportados. Alterar a opção de prioridade não limpa o arquivo selecionado nem torna seus campos obrigatórios. A senha nunca é reapresentada.

| Situação | Resultado |
|---|---|
| Prioridade local habilitada e arquivo cadastrado | Usa o A1 local da empresa. |
| Sem prioridade local | Consulta a integração da empresa e, quando aplicável, o serviço autorizado do Multiempresa. |
| Integrações ausentes e A1 local já cadastrado | Usa esse A1 como alternativa. |
| Integração nega acesso, devolve identidade divergente ou certificado inválido | Interrompe a operação; não ignora o problema para procurar outro certificado. |
| Prioridade local habilitada, mas sem arquivo | A resolução automática ainda pode localizar o certificado da empresa; a tela orienta cadastrar o arquivo ao habilitar a opção. |
| Nenhum A1 disponível | Informa a pendência antes de transmitir. |

A prioridade do A1 local não dispensa a conferência da sessão, da empresa ativa e do CPF/CNPJ. A mudança de configurações exige a permissão fiscal correspondente. O arquivo e sua senha são cifrados; o módulo nunca devolve senha, PFX ou chave privada em respostas JSON ou HTML.

## Compatibilidade dos arquivos PKCS#12

O módulo tenta primeiro `openssl_pkcs12_read()` do PHP. Se essa leitura falhar, utiliza a classe interna `Pkcs12Reader`, que chama uma instalação confiável do OpenSSL 3 em processo separado. O provider legado fica limitado a esse processo; o módulo não modifica `openssl.cnf`, variáveis de ambiente do processo PHP ou a lista de cifras da aplicação. O parâmetro `-legacy` é documentado pelo [OpenSSL](https://docs.openssl.org/3.5/man1/openssl-pkcs12/) para formatos como RC2.

A matriz local exercitou AES-128/192/256-CBC com PBES2, 3DES, RC2-40/128 e AES-256 com PBMAC1/PBKDF2, senhas Unicode, senha de 460 bytes e senha vazia. São certificados sintéticos de teste, não uma homologação de todas as autoridades certificadoras. A importação conserva a verificação de correspondência entre chave e certificado, RSA de pelo menos 2.048 bits, validade, rejeição de certificado de autoridade e identidade do emitente.

### Limites da leitura adicional

- Requer sistema Unix compatível com pipes, `proc_open` e OpenSSL 3 com provider legado. Não é usada no Windows. O caminho nativo do PHP continua disponível nesses ambientes.
- O executável é procurado somente em caminhos fixos do sistema; não existe seletor de tecnologia ou caminho na interface.
- O PFX entra pela entrada padrão e a senha por outro pipe. Não há shell, senha em argumentos/variáveis, gravação temporária do certificado nem exposição do retorno bruto em mensagens.
- Arquivo de até 4 MB; processo limitado a 8 segundos, saída de até 8 MB e parâmetros anunciados de até um milhão de iterações. Esses limites adicionais pertencem ao subprocesso; não limitam o custo interno da leitura nativa anterior.
- A senha do pipe admite até 1.023 bytes e não pode conter NUL, CR ou LF. Esses formatos não são truncados nem normalizados silenciosamente. A tentativa nativa ocorre antes desse limite.
- O fallback exige MAC presente e verificação bem-sucedida pelo OpenSSL; não usa `-nomacver`. O caminho nativo preserva o comportamento existente de `openssl_pkcs12_read()`, que também aceita certos PKCS#12 sem `MacData`. Não se declara obrigatoriedade uniforme de MAC para ambos os caminhos.
- Pacotes com múltiplas chaves ou mais de uma identidade correspondente são recusados pela extração adicional.

Não há garantia de suporte universal a qualquer criptografia/exportação PKCS#12. Algoritmos ausentes da biblioteca instalada, exportações com duas senhas distintas e formatos proprietários podem exigir nova exportação compatível. A senha e a chave privada devem permanecer no cadastro protegido do servidor.

## Integração com o Multiempresa fornecido

O contexto é obtido de `PME_CONTEXT_READY`, `pme_context` e `pme_app`, definidos pelo runtime. O módulo não aceita empresa ou organização informadas pelo navegador para escolher certificado.

Antes de solicitar as credenciais, são conferidos:

1. Tipo do contexto, prefixo e identificador da empresa/organização.
2. Funcionário da sessão Perfex e principal autenticado do runtime.
3. Empresa e organização ativas, com prefixo correspondente.
4. Identidade atualizada por `auth->refresh()` e versão de autenticação, quando disponível.
5. Vínculo atual do usuário com a empresa e seu funcionário local.
6. CPF/CNPJ do emitente e cadastro da empresa, quando disponível.

A obtenção usa `Multiempresa\Application\Certificates::credentials($actor, $companyId)`. Não há leitura direta de `pme_certificates`, acesso direto ao vault, pesquisa de outra empresa por CNPJ nem criação artificial de ator administrador.

**Autorização existente:** o serviço da versão fornecida do Multiempresa exige administração organizacional por `CompanyManager::managed()`. Um emissor que tenha somente a permissão fiscal não recebe esse acesso automaticamente. A política não foi ampliada neste módulo. A disponibilidade de um A1 local prioritário permite a emissão pelos colaboradores autorizados sem abrir acesso ao certificado central.

## Núcleo personalizado: contrato interno de extensão

Não foi disponibilizado o código do núcleo personalizado com um cadastro de A1. O material examinado não identifica um serviço nativo universal de certificados do Perfex. Portanto, esta entrega não pesquisa tabelas, arquivos ou opções arbitrárias tentando descobrir credenciais.

Foi definido um **novo contrato de extensão do módulo**, destinado ao desenvolvedor responsável por um serviço de certificados que já exista na aplicação:

```php
hooks()->apply_filters('nfse_nacional_company_certificate', null, $context);
```

O provedor registra um filtro com dois argumentos. O primeiro é o resultado anterior, e o segundo é um contexto criado no servidor:

| Chave do contexto | Conteúdo |
|---|---|
| `company_id` | Identidade da empresa atual ou `standalone` para instalação nativa. |
| `organization_id` | Organização atual, quando aplicável. |
| `scope` | Hash do contexto de execução; distingue banco, host, prefixo, empresa e organização. |
| `prefix` | Prefixo do banco atual. |
| `runtime` | `native` ou `multiempresa`. |
| `staff_id` | Funcionário autenticado na empresa. |
| `user_id` | Identidade do runtime, quando disponível. |
| `tax_id` | CPF/CNPJ esperado do emitente. |

O retorno deve ser:

- `null` quando não existir certificado disponível naquela integração.
- Uma exceção quando houver acesso negado, erro de leitura ou outra falha. Não converta esses casos em `null`.
- Um array contendo `company_id`, `scope`, `tax_id`, `pkcs12` e `password` quando houver um certificado autorizado.

`pkcs12` deve conter os bytes do PFX/P12 em memória. Não são aceitos caminhos locais ou URLs. `password` deve ser uma string, inclusive quando vazia. `company_id`, `scope` e `tax_id` precisam coincidir exatamente com o contexto; o certificado é aberto e seu titular é validado novamente pelo módulo.

**Responsabilidade do provedor:** a identidade do certificado deve ser comprovada pelo serviço de origem. Não basta copiar as chaves recebidas no contexto para rotular um arquivo obtido sem isolamento. O provedor precisa autorizar o funcionário, selecionar a empresa correta e usar o mecanismo de proteção já existente no núcleo.

O filtro não aparece na interface do usuário. Conectar um cadastro A1 específico do núcleo exige conhecer o contrato real desse serviço; esse adaptador específico permanece pendente por ausência do código do núcleo.

## Migração e testes

A versão anterior utilizava somente o A1 local. A migração `020` preserva sua prioridade quando esse certificado já existe. A configuração explícita de prioridade não é sobrescrita nas leituras seguintes.

A suíte `certificate_resolver_test.php` testa seleção, identidade e bloqueios. Com o código do Multiempresa e PDO SQLite disponíveis, também exercita as classes reais de autenticação, catálogo, certificados e auditoria em banco temporário, incluindo duas empresas com o mesmo CNPJ e revogação de acesso. Não utiliza certificado ICP-Brasil real nem acessa a rede.
