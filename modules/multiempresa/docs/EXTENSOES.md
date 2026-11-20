# Extensões polimórficas

`config/extensions.php` recebe a instância do módulo e registra implementações. Nenhuma integração de pagamento específica é embutida. O arquivo é código administrativo confiável e não deve receber uploads de usuários.

| Contrato | Função |
| --- | --- |
| `StorageDriver` | Conectar, validar o destino e enumerar tabelas; inclui `database` e `prefix` |
| `EventAuthenticator` | Validar assinatura e retornar um ID de evento estável |
| `EventProcessor` | Processar o evento no `Context` imutável da empresa |
| `MigrationAdapter` | Conferir pré-requisitos, migrar e validar uma versão |

Registro de implementação própria:

```php
return static function (\Multiempresa\Application\App $app): void {
    $app->registerAuthenticator('meu_provedor', new MinhaAutenticacao());
    $app->registerProcessor('meu_processador', new MeuProcessador());
};
```

Os nomes registrados são exibidos automaticamente no formulário de integrações. Os dados do evento podem representar qualquer entidade; o processador define sua interpretação. IDs fornecidos pelo payload não substituem a empresa vinculada à integração.

## HMAC genérico

Crie uma integração com autenticação `hmac` e destino `durable_inbox`. O segredo é exibido uma única vez. Envie JSON com um campo `id` textual estável e estes cabeçalhos:

- `X-PME-Timestamp`: timestamp Unix em segundos, com tolerância de cinco minutos.
- `X-PME-Signature`: HMAC SHA-256 hexadecimal de `timestamp + "." + corpo_original`, usando o segredo da integração.

Use exatamente a URL exibida. A empresa é resolvida pelo endpoint armazenado. O evento é persistido antes da confirmação. Mesmo ID e mesmo corpo retornam duplicado; mesmo ID com corpo diferente gera conflito. A caixa de entrada é genérica e não cria lançamentos financeiros automaticamente.

## API

Gere um token com os escopos necessários e envie `Authorization: Bearer TOKEN`.

```text
modules/multiempresa/tenant.php/api/v1/context
modules/multiempresa/tenant.php/api/v1/clients
modules/multiempresa/tenant.php/api/v1/invoices
modules/multiempresa/tenant.php/api/v1/events
```

A URL empresarial correspondente também é aceita; uma divergência entre a empresa da URL e a do token é recusada. A API fornecida é de leitura. Clientes e faturas exigem a permissão nativa de visualização do funcionário local. Listagens de clientes/faturas aceitam `limit` de até 100 e `offset`; segredos e dados internos de conexão não são retornados.

## Homologação de módulos

A aprovação registra o conteúdo do código. Confira uso de `db_prefix()`, caminhos derivados do contexto, sessões, permissões, cron e callbacks. Conexões externas, caches globais, caminhos absolutos ou SQL que usa tabelas de outra empresa exigem adaptação. O módulo impede habilitação automática sem essa aprovação; a existência de um contrato não garante compatibilidade universal.

## Tabelas e privilégios de módulos no banco compartilhado

O modo automático por prefixo usa um usuário MySQL exclusivo por empresa. Recebe GRANTs das tabelas exatas, não do banco compartilhado inteiro. O provisionador identifica nomes literais derivados de `db_prefix()` nos arquivos PHP do módulo aprovado. Para nomes calculados dinamicamente, forneça `multiempresa.json` na raiz desse módulo externo:

```json
{
  "tables": ["minha_extensao_documentos", "minha_extensao_eventos"]
}
```

São sufixos sem o prefixo empresarial, apenas identificadores válidos. Declare também tabelas novas criadas por suas migrations. A aprovação concede os GRANTs antes de iniciar o worker nativo; SQL para nomes não declarados pode falhar de forma explícita. No modo manual, o administrador do banco prepara os mesmos GRANTs para a conexão informada. Não use uma conta global para contornar o isolamento.

Módulos externos continuam executando código PHP confiável no mesmo servidor. A separação de contexto e privilégios SQL não constitui sandbox para código PHP malicioso com acesso ao sistema operacional. Não homologue módulos que abram conexões globais, leiam a configuração privada, ignorem `db_prefix()` ou mantenham caches globais sem chave de empresa.

O módulo Multiempresa nunca é uma extensão empresarial selecionável. Os controllers internos de API/worker são ligados ao runtime privado, sem instalar o módulo administrativo no catálogo da empresa.

## Consultas cadastrais e certificado

CNPJ/CEP são serviços internos, sem parâmetros de provedor. CNPJ usa BrasilAPI, CNPJWS pública e ReceitaWS pública; CEP usa ViaCEP e BrasilAPI. O transporte limita tempo/tamanho, valida HTTPS e endereços públicos e não envia credenciais. O orçamento total e o fallback são administrados por Lookups; respostas incompatíveis ou divergentes do documento consultado são rejeitadas.

Integrações internas autorizadas acessam A1 por `Certificates::credentials($actor, $companyId)`, com ator autenticado e autorização de administração da organização. A API pública não exporta a chave nem a senha. O método entrega bytes PKCS#12 e senha somente em memória e registra o acesso; o consumidor não deve gravá-los em logs ou retornar os segredos ao frontend. Se precisar de arquivo temporário para outra biblioteca, crie-o fora do DocumentRoot, com permissão restrita, e remova-o ao terminar.

Testes de contratos podem injetar um transporte em `Infrastructure\HttpClient`. Esse ponto de teste é uma instância criada no servidor; não é selecionável por parâmetros HTTP e não permite ao usuário fornecer código ou resposta simulada.

Referências dos formatos de integração: [CNPJWS](https://docs.cnpj.ws/), [ReceitaWS](https://developers.receitaws.com.br/), [BrasilAPI](https://brasilapi.com.br/docs), [ViaCEP](https://viacep.com.br/) e [Cloudflare DNS](https://developers.cloudflare.com/api/resources/dns/).


## Arquivos de módulos por empresa

Cada módulo aprovado é copiado para o armazenamento privado da empresa. O runtime aponta para essa cópia. Arquivos gerados por instaladores, como licenças locais, não mudam o hash do pacote central nem são compartilhados entre empresas. Na atualização, arquivos distribuídos são sincronizados e arquivos gerados localmente são conservados. Controllers e bibliotecas nativas instaladas por extensões também são privados, com registro de propriedade; desativar uma extensão remove seus carregadores privados sem alterar a central.

O adaptador de SQL modifica somente identificadores de tabelas conhecidas em arquivos de seed e em strings PHP que começam com um comando SQL. Ele interpreta os escapes PHP sem executar o código e preserva comentários, valores SQL e strings que não representam consultas. A adaptação também alcança os instaladores e as migrations de `purchase`, que usam `tblpurchase_option` fixo. Um novo adaptador invalida as cópias privadas antigas automaticamente, sem alterar a aprovação do pacote original.

A adaptação de inclusões PHP limita-se a caminhos do próprio módulo baseados em `__DIR__`. Caminhos arbitrários, dependências externas, consultas em strings interpoladas/heredoc, nomes de tabelas calculados dinamicamente, referências qualificadas a outros bancos e licenciamento comercial não são corrigidos genericamente. O código original permanece intacto, e a compatibilidade continua sujeita à revisão. Os testes desta versão incluem os pacotes fornecidos de `ma`, `fleet`, `gerencianet_gateway` e `purchase`.
