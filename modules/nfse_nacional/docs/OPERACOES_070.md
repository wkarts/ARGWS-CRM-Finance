# Documentos nacionais, recuperação e eventos fiscais

Versão **0.7.0**, preparada em **10/11/2026**. Os fluxos usam as permissões, o contexto empresarial, o A1 e o envio de e-mail existentes no módulo/Perfex. As fontes originais do DANFSe continuam incorporadas ao pacote.

## Quando a resposta da emissão se perde

Uma conexão interrompida não comprova rejeição. A aplicação salva o identificador e o XML assinado da DPS antes do envio; a nota permanece **Aguardando consulta**, com confirmação pendente, quando o resultado é inconclusivo.

1. Abra o documento pela fatura ou por **NFSE → Documentos → No CRM**.
2. Clique em **Consultar transmissão**. O módulo consulta a DPS no mesmo ambiente e, se houver chave, recupera o XML autorizado.
3. Caso já conheça a chave, use **Já tenho a chave de acesso**. Essa opção exige uma tentativa anterior com sua DPS original preservada.
4. O retorno só é vinculado depois da conferência da DPS, emitente, ambiente e conteúdo da operação. Uma nota diferente não pode ser anexada por coincidência de numeração.
5. Confirmada a autorização, ficam disponíveis o XML oficial, DANFSe, chave, número e registros de processamento efetivamente recebidos. O histórico registra a recuperação.

Consultar não reserva outro número nem executa outro POST fiscal. Se a consulta não confirmar o resultado, o documento permanece pendente. Um HTTP 404 genérico não autoriza retransmitir.

O reenvio é uma ação explícita e separada: consulta primeiro e, quando permitido, reutiliza **exatamente a DPS assinada original**. Não gera uma nota nova. Nas substituições, também revalida permissão, origem e ausência de pedido de cancelamento conflitante.

O XML da NFS-e é guardado como recebido. O módulo não injeta `nProt`, não altera o XML autorizado e não apresenta a chave ou o ID da DPS como um protocolo. Identificação de processamento e registro de evento são mostrados pelos nomes correspondentes aos dados oficiais.

## Guias de Documentos

| Guia | Conteúdo |
| --- | --- |
| No CRM | Rascunhos e emissões vinculados às faturas, incluindo revisões e substituições |
| Emitidas no ambiente nacional | Notas em que a empresa é prestadora, distribuídas ao seu certificado |
| Recebidas | Notas em que a empresa é tomadora, distribuídas ao seu certificado |
| Pacote mensal | Conferência, download e envio de documentos por competência |

As guias nacionais usam a distribuição do ADN por NSU. A sincronização é incremental e separada por empresa, CPF/CNPJ e ambiente. Cada lote grava documentos, eventos e avanço do cursor em uma mesma transação. Um lote inválido ou uma falha de gravação não avança o cursor.

Após um lote com documentos, a tela permite continuar a consulta. Ao terminar o histórico disponível, a próxima consulta respeita a espera mínima implementada de uma hora; respostas de limitação também respeitam o intervalo informado. Falha de permissão ou rota não é tratada como fim do acervo.

Não há criação de clientes, faturas ou cobranças artificiais para documentos externos. As notas ficam no acervo fiscal, com seus XMLs cifrados, pesquisa, prévia e downloads. Eventos de cancelamento podem chegar antes da nota e são aplicados quando seu documento correspondente estiver disponível.

**Alcance:** o certificado só acessa os documentos que o ADN lhe disponibiliza. A sincronização não garante todo o histórico desde a abertura da empresa e não representa consulta irrestrita a qualquer CNPJ. A emissão, cancelamento e demais operações dependem da habilitação, origem da nota e regras da administração tributária competente.

## Cancelamento e manifestações

Na página da nota, **Eventos fiscais** apresenta apenas as operações compatíveis com o papel da empresa e as permissões do colaborador.

| Evento | Operação | Efeito local após confirmação |
| --- | --- | --- |
| 101101 | Cancelamento pelo emitente | Nota cancelada, XML original preservado e evento disponível |
| 101103 | Solicitação de análise fiscal de cancelamento | Pedido registrado; não equivale a cancelamento aprovado |
| 203202 | Confirmação pelo tomador | Manifestação registrada, sem cancelar a nota |
| 203206 | Rejeição pelo tomador | Manifestação registrada, sem cancelar a nota |

O formulário identifica a nota e o ambiente antes da confirmação. Motivo e justificativa seguem o contrato do evento. O pedido assinado é gravado antes do POST, com trava por nota e proteção contra clique duplo. Em caso de resposta perdida, **Consultar** tenta recuperar o evento antes de qualquer reenvio.

O reenvio explícito exige o mesmo XML original, com assinatura, chave, ambiente, tipo e autor conferidos. Se o evento já estiver registrado, o módulo reaplica a prova salva; não envia novamente. Uma falha na atualização do estado local não descarta uma resposta oficial válida já persistida.

O download de XML do evento só fica disponível após registro confirmado. Pedido de análise, timeout, erro HTTP ou manifestação de recebimento não criam um carimbo de cancelamento. O DANFSe cancelado mantém os dados da autorização e exibe a situação confirmada.

## Substituição de uma emissão do CRM

**Preparar substituição** cria um novo rascunho vinculado à nota original e à mesma fatura. Essa preparação não transmite e não cancela. A nova emissão terá outra DPS, numeração e chave; as versões anteriores continuam acessíveis.

O tomador não pode ser trocado por substituição. CPF/CNPJ e competência ficam somente para leitura. As restrições sobre serviço, local da prestação e valor são verificadas conforme o regime e o motivo selecionado, além das regras aplicadas pela SEFIN. A correção de tomador diferente exige avaliar o procedimento fiscal apropriado, não trocar a identidade na substituta.

Após autorizar a substituta, o módulo consulta os eventos da original. A original somente é marcada como cancelada quando houver prova fiscal correspondente. Se essa consulta falhar, a nova autorização é mantida e a tela orienta consultar a situação da original.

Nesta versão, a preparação de substituição é oferecida para notas originadas no módulo e vinculadas a uma fatura real. A substituição de nota apenas importada do acervo nacional não está implementada.

## Pacote mensal para a contabilidade

Cadastre os destinatários na guia **Contabilidade**, em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**. Os destinatários pertencem à empresa ativa. Não há escolha de tecnologia de envio.

Em **Documentos → Pacote mensal**, selecione competência, ambiente, emitidas/recebidas/ambas e XML/PDF/ambos. Clique em **Conferir documentos** para ver a composição; depois baixe o ZIP ou selecione os destinatários para enviar.

A competência é `dCompet` da nota. São incluídos documentos autorizados e cancelados disponíveis localmente, combinando CRM e acervo nacional, sem duplicar a mesma chave no mesmo ambiente. Rascunhos não entram. Cancelamentos confirmados incluem a evidência e são identificados na relação de arquivos; o PDF exibe o carimbo correspondente.

O ZIP contém pastas por direção e uma relação CSV. Antes de baixar ou enviar, a composição é conferida novamente: se os XMLs, eventos ou situações mudaram, o sistema exige nova conferência. Não entrega um pacote parcial sem avisar.

Limites desta versão: até **1.000 notas**, **100 MiB descompactados** por pacote e **20 MiB** para o ZIP enviado por e-mail. Podem ser cadastrados até dez destinatários. O gerador ZIP está no módulo e não exige a extensão `zip` no servidor.

O envio usa a configuração nativa do Perfex e registra tentativa, destinatários, competência e resultado. O mesmo pacote não é enviado novamente aos mesmos destinatários por um clique repetido. Se o SMTP não confirmar o resultado, fica em verificação, sem repetição automática; a aceitação pelo servidor não comprova entrega na caixa postal.

Esta seleção não fecha nem bloqueia o mês. Sincronize o acervo antes de conferir para incluir o histórico disponível mais recente.

## Autorização e implantação

Novas capacidades: `national_view`, `national_sync`, `accounting_send`, `cancel`, `substitute` e `manifest`, além da visualização fiscal e dos controles existentes. Administradores autenticados seguem o padrão nativo; colaboradores precisam das permissões aplicáveis. Uma consulta de transmissão continua sendo permitida a quem pode visualizar a nota, mesmo sem permissão de retransmitir.

As migrações `060` e `070` afetam exclusivamente tabelas do módulo. Documentos existentes começam na revisão zero. O índice de revisões é criado antes da remoção da unicidade anterior; uma atualização interrompida pode ser retomada. Não desinstale o módulo para atualizar.

Os testes locais usam certificado e documentos sintéticos, transporte controlado e persistência isolada. Eles verificam os fluxos e os cenários de falha, mas não substituem a homologação autenticada com A1 real e habilitação do emitente. Não são implementadas APIs de administração municipal nem todos os leiautes especiais de IBS/CBS.

## Referências

- [Manual do contribuinte — Emissor Público API v1.2](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf): emissão, consulta de DPS/NFS-e e eventos.
- [Manual de distribuição ADN v1.1, de 03/11/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-municipios-apis-adn-sistema-nacional-nfs-e-v1-1-20261103.pdf): NSU e documentos distribuídos.
- [FAQ nacional v1.3, de 06/11/2026](https://www.gov.br/nfse/pt-br/perguntas-frequentes/perguntas-frequentes-nfs-e-v1-3-20261106.pdf): substituição e restrições de alteração.
- [Bases oficiais de produção e produção restrita](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao).

Os XSDs adicionais de eventos têm origem e hashes registrados em `schemas/events_provenance.json`. O download direto do pacote governamental não esteve disponível durante a preparação; os arquivos obtidos de espelho público não são apresentados como cópia binária conferida com o pacote oficial.
