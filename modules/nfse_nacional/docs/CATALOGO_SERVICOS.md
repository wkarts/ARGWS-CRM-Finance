# Catálogo de serviços da NFS-e Nacional

## Conteúdo distribuído

O módulo contém o catálogo de **códigos de tributação nacional (`cTribNac`)**, com código de seis dígitos e descrição integral. É a classificação do serviço da DPS, composta por item, subitem e desdobro nacional. Não é a tabela CNAE, a NBS nem o complemento de tributação municipal.

Consulta das publicações realizada em **10/11/2026**:

| Ambiente | Códigos | Base |
| --- | ---: | --- |
| Produção (`1`) | 338 | Folhas da aba `LISTA.SERV.NAC.` do Anexo B v1.01 de 22/01/2026 |
| Produção restrita (`2`) | 345 | Os 338 códigos, acrescidos dos sete novos fatos geradores 99.02 a 99.04 |
| União disponível para cadastro | 345 | Permite manter preferências e indicar as opções exclusivas de testes |

O Anexo B vigente é o arquivo apontado na [Documentação Atual de Produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual). A [documentação de homologação](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita) possui um Anexo B com identificação própria do ambiente.

Os códigos `990201`, `990301`, `990302`, `990303`, `990304`, `990305` e `990401` foram extraídos da tabela da [Nota Técnica 007, página 7](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-007-se-cgnfse-v1-0.pdf). A disponibilidade exclusiva em testes por API foi confirmada no [comunicado oficial de 29/10/2026](https://www.gov.br/nfse/pt-br/noticias/nfs-e-nacional-confira-oas-rientacoes-sobre-leiaute-em-producao-regras-de-preenchimento-de-ibs-cbs-e-status-da-nota-tecnica-009).

Não há ativação de códigos em produção pela simples passagem do tempo. Uma alteração de vigência exige nova conferência da publicação, atualização do catálogo e nova versão do módulo.

## Origem e conferência

O download direto dos binários XLSX no gov.br retornou HTTP 403 neste ambiente. Foi usada uma [republicação pública do próprio Anexo B](https://meumunicipio.zendesk.com/hc/pt-br/articles/52437737766292-GERAL-anexo-b-nbs2-lista-servico-nacional-snnfse-v1-01-20260122) para extrair as 338 linhas de produção. A cópia tem 87.747 bytes e SHA-256 `4c6dcf51a072ee0ba06f89fc506cd34d04fcb72f4f499b018bcd40b858ff6adc`.

A conferência com fontes primárias cobriu:

- Os **335 códigos e descrições** da [página oficial de serviços](https://www.gov.br/nfse/pt-br/mei-e-demais-empresas/codigos-de-tributacao-nacional-nbs), atualizada em 2024. Todos coincidem com a planilha, inclusive descrições extensas.
- O código `110501`, também publicado na [Lei Complementar 51/2025 de Iúna](https://camaraiuna.es.gov.br/legislacao/consolidado/2871/lei-complementar-51-2025.html), com sua descrição de monitoramento e rastreamento.
- A existência e finalidade dos códigos `141403` e `141404`, publicadas na [NT 2025.001 de Londrina, página impressa 26](https://repositorio.londrina.pr.gov.br/index.php/menu-fazenda/ggf/nfse-nacional/71715-nt-2025-001-implantacao-da-nfse-padrao-nacional/file). A NT municipal usa a redação anterior “vinculado à obra”; o catálogo preserva a redação da planilha nacional “em construção civil”.

Não foi realizada comparação binária com o XLSX servido diretamente pelo gov.br. O binário de homologação também não foi obtido; os sete códigos adicionais usam a descrição da NT007 e a vigência do comunicado oficial. Esses limites estão registrados em `resources/servicos-source.json`, junto das URLs, contagens, hashes e regras de extração.

## Regras de utilização

A busca das telas pode comparar o código puro (`010101`), sua máscara (`01.01.01`) e palavras da descrição. O valor salvo e utilizado na DPS continua sendo a sequência de seis dígitos. A descrição oficial identifica a classificação; a descrição padrão da **prestação** é um texto próprio do tomador e pode detalhar o que foi efetivamente prestado.

`ServiceCatalog::all()` fornece a união para cadastros. Os métodos `all`, `find` e `has` aceitam um segundo argumento de ambiente no caso de `find/has`, ou o primeiro no caso de `all`, para verificar a disponibilidade. Exemplo:

```php
$services = \NfseNacional\ServiceCatalog::all();
$productionServices = \NfseNacional\ServiceCatalog::all('1');
$service = \NfseNacional\ServiceCatalog::find('010101');
$allowedForProduction = \NfseNacional\ServiceCatalog::has('990301', '1'); // false
```

`normalize()` aceita seis dígitos ou a máscara completa `NN.NN.NN`. Não completa códigos curtos, não remove caracteres arbitrários e não converte CNAE ou NBS.

O catálogo faz verificação de domínio. A habilitação do emitente, a parametrização municipal, a incidência e os requisitos específicos da prestação continuam sujeitos às regras da emissão. A presença de um código no catálogo também não implementa automaticamente os grupos especiais de obras, imóveis, bens móveis, comércio exterior ou IBS/CBS no gerador de DPS.

O gerador atual possui suporte ao grupo básico de IBS/CBS, mas não monta os grupos patrimoniais `imovel` e `bensMoveis`. A [NT009 v1.01, seções 2.6 e 2.7](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nota-tecnica-009-se-cgnfs-e-v-1-01.pdf) trata dos dados de imóveis e locações para as operações `99.03` e da denominação `bensMoveis`. A [NT005, seção 2.1.3](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-005-se-cgnfse-novo-layout-rtc.pdf) relaciona o grupo de locação de bens móveis ao código `990401`, com NCM, descrição e quantidade. As ocorrências opcionais no leiaute não substituem as regras de negócio aplicáveis a cada operação. Não foi identificada nessas seções uma exigência de grupo patrimonial comum aos sete códigos; por isso o catálogo não introduz um bloqueio genérico deles em testes. Também não declara homologação de todas essas operações. O comunicado de implantação deve ser conferido antes de aplicar alterações da NT009 v1.01, pois a publicação de uma nota técnica não significa que todas as suas funcionalidades já estejam disponíveis.

Preferências já gravadas e documentos anteriores não devem ser reescritos durante atualizações do catálogo. Quando uma classificação deixa de ser aceita, o documento pode ser consultado e deve ser revisado antes de nova transmissão.

## Extração e atualização técnica

O importador `tools/import_service_catalog.py` usa apenas a biblioteca padrão do Python. Ele não executa macros, fórmulas nem código da planilha. A extração:

1. Localiza exatamente a aba `LISTA.SERV.NAC.`.
2. Seleciona apenas linhas com código na coluna A.
3. Confere o código com item, subitem e desdobro das colunas B, C e D.
4. Preserva a descrição completa da coluna E, removendo apenas espaços externos.
5. Recusa duplicatas, descrições vazias, estrutura inesperada e divergências de total.
6. Extrai da camada textual da NT007 os sete códigos de teste, sem transcrição manual de descrições.

Exemplo com os arquivos oficiais baixados pelo mantenedor:

```sh
pdftotext -layout NT007.pdf NT007.txt
python3 tools/import_service_catalog.py ANEXO_B.xlsx --nt007-text NT007.txt
```

Após conferir diferenças de conteúdo e vigência, atualizar a proveniência, o total e o hash pinado na classe `ServiceCatalog`; executar `tests/service_catalog_test.php` e os testes de perfil/rascunho. O importador desta versão exige os totais conhecidos de 338 e 7, de forma que uma publicação futura nunca seja incorporada silenciosamente.

A reprodução com os arquivos de origem registrados em `resources/servicos-source.json` gerou novamente o SHA-256 `f18c22d63023e74099924581390bcd0619885deeb396f74fa06059e89be28c07`, idêntico ao recurso distribuído. Esse hash refere-se ao JSON gerado; não deve ser confundido com o hash da planilha de origem.

O catálogo é um recurso local comum e sem informações de clientes. A preferência de serviço e a descrição padrão permanecem nos cadastros fiscais isolados da empresa ativa.
