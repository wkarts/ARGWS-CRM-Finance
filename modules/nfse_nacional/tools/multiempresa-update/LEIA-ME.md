# Atualizar um módulo ativo no Multiempresa 1.2.4

Correção **opcional**, destinada exclusivamente à base 1.2.4 conferida. Acrescenta **Atualizar módulo** ao gerenciamento de um módulo ativo. O cadastro da empresa, as permissões, a sessão, o CSRF e o fluxo de backup/ativação do Multiempresa são reaproveitados.

O módulo NFS-e não aplica esta correção nem aprova sua própria atualização.

Este patch foi disponibilizado com a NFS-e 0.4.1 e não recebeu nova alteração na versão 0.4.2. Se a ação de atualização já está disponível na instalação, use o fluxo existente; não reaplique o patch para atualizar o pacote da NFS-e. O procedimento atual está em [ATUALIZACAO_042.md](../../docs/ATUALIZACAO_042.md).

## Aplicar a correção na instalação principal

Os quatro arquivos deste conjunto devem permanecer juntos:

- `multiempresa-1.2.4-atualizar-modulo.patch`
- `manifest.json`
- `apply_patch.py`
- `LEIA-ME.md`

O aplicador requer Python 3. Execute primeiro a conferência, informando a pasta **física do Multiempresa da instalação principal**:

```bash
python3 apply_patch.py /caminho/do/crm/modules/multiempresa --check
```

Ele verifica a versão, os hashes dos cinco arquivos alterados, oito arquivos relevantes à integração e a integridade do patch. Uma versão diferente, arquivo personalizado, arquivo já corrigido ou vínculo simbólico em um dos alvos é recusado antes de qualquer escrita. Se houver divergência, a correção precisa ser adaptada à base instalada; não force a aplicação nem altere o manifesto para ignorá-la.

Com a conferência concluída e a instalação fora de uma atualização concorrente, aplique indicando uma **pasta nova e privada para os originais, fora da instalação web**:

```bash
python3 apply_patch.py /caminho/do/crm/modules/multiempresa --apply --backup-dir /caminho/privado/backup-multiempresa-124
```

O aplicador preserva os originais, os modos de permissão, o proprietário e o grupo dos arquivos substituídos. Se não conseguir preservar proprietário e grupo, a substituição do arquivo é recusada. Não acessa banco de dados, não ativa módulos, não emite documentos e não efetua aprovação. Não exige alteração de tabelas ou migração. Se a hospedagem não revalida PHP automaticamente, recarregue o serviço PHP pelo procedimento normal da hospedagem.

## Atualizar a NFS-e na empresa

1. Substitua o pacote completo de NFS-e na pasta `modules/nfse_nacional` da instalação principal.
2. Acesse **Multiempresa → Módulos por empresa** na administração principal e abra a empresa desejada.
3. Confira a versão disponível do módulo e marque **Conferi a compatibilidade dos módulos a ativar ou atualizar neste ambiente**.
4. Clique em **Atualizar módulo** na linha de **NFS-e Nacional**. Aguarde a resposta antes de iniciar outra operação.
5. Reabra a empresa. Para o pacote atual, a versão da NFS-e deve aparecer como **0.4.2** nas verificações recolhidas de **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**.

Não é necessário desativar o módulo. **Ativar todos** continua tratando somente os módulos inativos; ele não executa atualizações em lote.

## O que foi alterado

| Arquivo do Multiempresa | Alteração |
|---|---|
| `assets/companies.js` | Mostra a versão disponível e o botão para módulos ativos, exige a revisão explícita, envia a operação pelo transporte existente e impede cliques concorrentes. |
| `views/companies.php` | Esclarece a atualização e ajusta o texto da revisão existente. |
| `controllers/Multiempresa.php` | Encaminha a reaplicação à API `Modules::approve()` depois das verificações existentes de permissão, organização e estado da empresa. |
| `libraries/Application/Modules.php` | Reutiliza a gravação da revisão da release vigente **dentro da aprovação**, sob os locks e a manutenção existentes, depois do backup e antes do worker. Isso resolve a precedência da revisão antiga sem permitir que uma requisição da empresa antecipe a montagem do PHP novo. |
| `views/manage.php` | Usa o hash do `companies.js` na URL para carregar o script correspondente à correção. |

São mantidos o backup, a execução do worker, a ativação/migração e os registros de auditoria já existentes. Não há aprovação em GET, revisão pré-marcada ou alteração de fingerprints fora da API de aprovação.

Se a atualização falhar, examine o retorno e os registros em **Atualizações e backups** antes de repetir. O fluxo original pode manter uma empresa em manutenção quando a atualização de um módulo já ativo falha; esta correção **não acrescenta rollback automático** para esse caso. O backup e o registro da falha seguem o comportamento original do Multiempresa.

## Validação realizada

Verificações locais do serviço e do controller reais com dependências isoladas: acesso por POST, permissão, organização/empresa, revisão explícita, módulo ativo, manutenção, revisão antiga com precedência, ordem backup → revisão → worker, estados finais e falhas. Componentes DOM: exibição do botão, bloqueio por revisão/permissão/incompatibilidade, fila e payload POST, CSRF, cliques repetidos e atualização somente do módulo escolhido. O aplicador foi verificado contra a base exata e contra arquivos divergentes.

Esses testes não substituem a validação no núcleo licenciado, banco e tema da instalação. Nenhuma operação foi executada no servidor do usuário.
