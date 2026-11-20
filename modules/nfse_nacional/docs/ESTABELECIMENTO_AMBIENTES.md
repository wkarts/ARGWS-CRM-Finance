# Endereço do estabelecimento e ambientes

Versão **0.5.0**. Conferência documental em **10/11/2026**.

## Cadastro do estabelecimento

Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional → Emitente**, informe CEP, logradouro, número, complemento, bairro, município, UF e código IBGE. CNPJ e CEP possuem o botão **Consultar**. A consulta preenche os dados retornados para revisão; o salvamento continua sendo o botão nativo **Salvar Configurações**.

Os campos pertencem a `settings.provider` no JSON existente da empresa ativa. A instalação não precisa de novas colunas no Perfex. Na ausência de um endereço fiscal local, o módulo reaproveita o endereço nativo da empresa como conjunto. Um endereço local parcial não recebe campos de outro endereço. Número e complemento não são inventados pela consulta de CEP. Inscrição municipal só é preenchida quando expressamente retornada; não se confunde com inscrição estadual.

CEP é normalizado para oito dígitos e a UF para duas letras. Município e UF permitem resolver o IBGE no catálogo local. O salvamento recusa combinações incompatíveis de UF, município e IBGE, preservando a configuração anterior. Campos ausentes em uma atualização parcial não apagam o endereço já salvo.

## Prévia e documento autorizado

O endereço do estabelecimento é copiado para os novos rascunhos. Salvar um rascunho ainda sem DPS reservada atualiza os dados do emitente; documentos com DPS reservada e documentos autorizados preservam sua identidade fiscal. A prévia utiliza o snapshot salvo.

A emissão atual é feita pelo próprio prestador (`tpEmit=1`). O endereço e o nome do emitente na NFS-e autorizada provêm do cadastro oficial RFB/CNC. Por isso o módulo não insere `prest/end` nem `prest/xNome` na DPS desse fluxo. O DANFSe autorizado utiliza o XML recebido, sem substituir o endereço oficial pelos parâmetros locais.

Fonte: [Perguntas frequentes oficiais, versão 1.3 de 06/11/2026](https://www.gov.br/nfse/pt-br/perguntas-frequentes/perguntas-frequentes-nfs-e-v1-3-20261106.pdf), seções 7.8 e 30.2. Se o endereço oficial estiver incorreto, sua correção deve ocorrer na base RFB/CNC aplicável.

## Apontamentos conferidos

| Serviço | Produção restrita / homologação (`tpAmb=2`) | Produção (`tpAmb=1`) |
|---|---|---|
| SEFIN: emissão, consulta e eventos | `https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional` | `https://sefin.nfse.gov.br/SefinNacional` |
| ADN: parâmetros municipais | `https://adn.producaorestrita.nfse.gov.br/parametrizacao` | `https://adn.nfse.gov.br/parametrizacao` |

A diferença `/API/SefinNacional` em homologação e `/SefinNacional` em produção é a publicada pelo governo. `/docs/index` e `/docs/index.html` são páginas de documentação, não os destinos de transmissão. As bases são fixas no módulo e não são aceitas URLs fornecidas pelo navegador.

O ambiente da conexão deve ser o mesmo do XML. Documentos já preparados continuam vinculados ao seu próprio ambiente mesmo que a configuração da empresa seja alterada depois. Produção exige a opção **Habilitar transmissão em produção**. Falhas não provocam troca automática entre produção e homologação.

Fontes: [APIs de produção restrita e produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao), atualizada em 20/08/2026, e [Manual do Emissor Público API v1.2](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf).

## Alcance da conferência

As bases foram comparadas com a documentação oficial, e os métodos/URLs do cliente foram exercitados com transporte simulado nos dois ambientes. Isso não constitui homologação autenticada com um A1 real. A documentação Swagger não ficou acessível nesta sessão. O caminho municipal corrente não foi alterado por tentativa: um HTTP 404 sem resultado fiscal conclusivo não comprova ausência de convênio. Nenhuma nota real foi transmitida para verificar apontamentos.
