# Operação — 1.2.0

## Cadastro independente

Use **Salvar cadastro** no wizard para registrar a empresa sem executar banco, módulos ou DNS. A grid oferece **Provisionar** para configurar o destino e iniciar depois, no mesmo registro. Até essa etapa não há CRM empresarial ativo. Nome e dados cadastrais continuam editáveis.

## Configuração global

Somente o administrador global configura integrações. Campos de senha/token vazios mantêm o segredo já salvo; o navegador recebe apenas a indicação de credencial configurada. Nunca coloque senhas em URLs ou exemplos. Mudanças globais se aplicam aos novos cadastros. Destinos existentes permanecem vinculados ao inventário original.

**MySQL:** selecione automático/manual e banco exclusivo/prefixo. Informe host, porta, usuário administrativo, senha, padrão do banco e host autorizado para a conta de aplicação. `localhost` só é adequado se PHP e MySQL se conectarem a partir desse host; use o host correspondente à infraestrutura. `root` é aceito na conexão administrativa, mas não é entregue à empresa. Em prefixo automático, informe um banco compartilhado existente. A credencial administrativa precisa poder criar os recursos, usuários e GRANTs previstos.

**DNS (opcional):** para usar domínios personalizados, informe token Cloudflare, zone ID, domínio base, tipo A/AAAA/CNAME, destino, TTL e proxy. O token deve permitir leitura e edição de DNS na zona selecionada. `{org}` participa obrigatoriamente dos nomes; endereços individuais também incluem `{company}`. Exemplo: `grupo.crm.example.com`, `matriz-grupo.crm.example.com`. Slugs de organizações diferentes são únicos. Se habilitar destino por organização, o cadastro pede somente esse destino específico; credenciais continuam globais.

Sem DNS habilitado, organização e empresas usam rotas isoladas no endereço atual da aplicação. Com domínios personalizados habilitados, cada organização tem seu próprio endereço. No modo compartilhado, uma empresa usa `https://grupo.crm.example.com/<identificador-aleatorio>/`; no modo individual, usa a raiz do seu host. DNS manual é possível mediante confirmação global de que os registros e o servidor foram preparados. Nesse modo, a etapa identifica explicitamente o DNS como manual; não afirma ter criado registros externos.

Salvar a organização não muda imediatamente o apontamento aplicado. Use **Aplicar DNS**, revise nomes/destinos/empresas e confirme. Novas empresas conservam o padrão já aplicado à organização. Mudanças de padrões/destinos para uma organização existente passam por esse plano. Registros antigos são mantidos no inventário até exclusão expressa, e deixam de ser rotas válidas quando substituídos.

**CNPJ e CEP:** recursos internos disponíveis desde a instalação ou atualização. Não há configuração nem botão de ativação. O módulo administra serviços públicos, ordem, limites, respostas, cache e alternativas. Falha de consulta não impede o cadastro manual.

## Criar organização e empresa

1. Cadastre nome e identificador da organização na grid Organizações. O identificador é permanente.
2. Abra Nova empresa: nome, organização, identificador, idioma/fuso, cadastro/endereço e A1 opcional.
3. O wizard mostra o modo global. No automático, não repete credenciais administrativas. No manual, pede a conexão de aplicação e o prefixo específico.
4. Escolha módulos individualmente ou use Selecionar todos/Desmarcar todos. Confirme sua compatibilidade. Multiempresa não é elegível.
5. Revise e inicie. As etapas são banco, estrutura, cadastro/configurações, módulos, A1 quando enviado, DNS e verificação final.
6. Somente a conclusão de todas as etapas libera o acesso. A tela final oferece abertura, edição e logs.

Ao completar um CNPJ ou CEP válido, a consulta inicia automaticamente após uma breve pausa; sair do campo também dispara a verificação, sem duplicar a requisição. Um indicador discreto acompanha o carregamento. O CNPJ informado é validado também no backend. Campos vazios não alterados podem ser preenchidos; campos existentes ficam preservados e as diferenças aparecem como sugestões que precisam ser marcadas. Uma consulta indisponível não impede preenchimento manual. CNPJ vazio é permitido; quando informado, precisa ser válido.

No modo manual, não use root ou conta com privilégios globais, GRANT OPTION ou acesso a outro prefixo. Banco exclusivo exige permissões do escopo exato; em GRANT de banco, escape `_` como `\_` para não autorizar nomes semelhantes. Em banco compartilhado, conceda privilégios por tabela e informe o prefixo já autorizado. Não há wildcard seguro por prefixo de tabela no GRANT MySQL. A validação exige SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX e REFERENCES para as tabelas necessárias. O modo automático prepara essas permissões. Views e estruturas fora do contrato genérico exigem adaptação.

Para vincular um Perfex já instalado, marque **Vincular uma instalação existente**, informe conexão restrita, prefixo, APP_ENC_KEY original e ID de administrador local ativo com o mesmo e-mail da identidade central. A versão deve corresponder ao núcleo publicado antes de alterações. Uploads/mídia informados são copiados; symlinks externos são rejeitados. Módulos da instalação vinculada também precisam ser homologados. Vincular não copia dados para a central nem apaga dados do destino.

## Estados, falhas e retomada

| Estado da execução | Significado e ação |
| --- | --- |
| Cadastro/pending | Reserva criada, etapas ainda pendentes; abrir Andamento |
| running | Uma etapa está em execução; requisições concorrentes não duplicam o trabalho |
| interrupted | O processo terminou sem confirmar a etapa; revisar diagnóstico e plano de retomada |
| failed | Etapa com erro; corrigir a causa, consultar logs e confirmar Retomar |
| done | Todas as etapas obrigatórias concluídas; empresa liberada |

O wizard tem Cancelar, Voltar, Continuar e contador de etapas no rodapé, fora da área rolável. Os títulos das etapas já visitadas permitem voltar diretamente. O modal é centralizado e a barra de progresso tem 20 px, cantos retos e percentual legível. Fechar o wizard não desfaz a etapa já enviada. As etapas seguintes aguardam a continuação. Consulte Andamento antes de enviar outro cadastro. Uma chave de requisição repetida retorna o mesmo processo; dados diferentes com a mesma chave são recusados.

O cadastro inicial existe antes do DDL e não é tratado como reprovisionamento. As tabelas recebem marcador de propriedade antes da criação do núcleo. O diário de bootstrap permite retomar estrutura/seeds confirmados sem duplicação. Mudança inesperada do modelo durante uma criação parcial exige revisão, para não misturar esquemas.

Em falha, os logs mostram execução, etapa, classe da exceção, SQLSTATE/código do driver quando disponível, arquivo/linha e pilha sem argumentos. Não exibem a mensagem SQL bruta, senha ou corpo do certificado. O erro `schema_foreign_scope` da criação inicial foi substituído pelo tratamento de dependências de módulos: não são copiadas referências para dados da central; vínculos locais são aplicados quando a tabela do módulo selecionado estiver disponível.

**Revisar e retomar** permite voltar ao cadastro e à seleção de módulos. Organização, identificador e destino já usados permanecem protegidos. Módulos retirados são desativados ou ignorados; seus dados parciais permanecem. Mudanças confirmadas de código renovam a aprovação dos módulos afetados em conjunto. Etapas concluídas e compatíveis são conservadas. Uma instalação externa interrompida pode executar novamente seu hook; a idempotência desse código deve ser verificada. Se o módulo alterou dados de modo incompatível, utilize o backup e a recuperação antes de liberar a empresa. Não há rollback transacional fictício de DDL MySQL.

## Atualizar os módulos existentes

Substitua o pacote do módulo na instalação principal, pelo procedimento nativo. As empresas que já possuem esse módulo recebem o novo código e as migrations pendentes automaticamente, no próximo acesso ou no cron nativo. Não use reprovisionamento para distribuir uma versão nova.

A atualização conserva o estado ativo/desativado, os acessos e o provisionamento concluído. Módulos apenas disponíveis não são instalados nem ativados. O processo aguarda as requisições da empresa, faz backup cifrado e executa cada módulo em worker próprio. Uma falha restaura a versão anterior e agenda uma nova tentativa; consulte os logs da principal para corrigir o pacote quando necessário. Após a correção do código, a atualização é descoberta novamente. Não é preciso reinstalar o módulo nem executar SQL manualmente.

Atualizações sem mudança de esquema avançam a versão nativa sem exigir uma migration artificial. Migrações presentes devem respeitar a sequência nativa do Perfex. Rebaixamento de versão não é automático. Provisionamento inicial interrompido por pacote alterado pode continuar na mesma execução quando corrigido; **Revisar e retomar** permanece disponível para alterações administrativas da seleção.

## Reprovisionar, editar e excluir

**Editar** modifica cadastro, idioma, fuso, situação e módulos sem um reprovisionamento completo automático. A1 pode ser importado, substituído ou removido. Na edição, Ativar todos e Desativar todos executam as mesmas operações individuais para a empresa aberta e param no primeiro erro. Banco, organização, slug e prefixo continuam fixos; mudança de armazenamento usa restauração em destino vazio.

**Reprovisionar** agora é permitido. O plano mostra banco/prefixo, módulos e versões, configurações reaplicadas, endereços e recursos existentes. Exige aceite e nome digitado. Reutiliza dados e arquivos; não limpa o banco. Migrations externas podem alterar ou remover dados de suas extensões, conforme o código homologado. Os backups anteriores à instalação de módulos continuam ativos.

Cada plano dura 15 minutos, pertence ao operador e só pode ser confirmado uma vez. Mudanças no inventário, organização, configurações globais ou código dos módulos invalidam a revisão. Uma nova execução tem ID próprio e mantém o histórico anterior nos logs.

**Remover empresa**, disponível na própria grid, exclui somente aquela empresa. A organização permanece, inclusive quando fica sem empresas. **Excluir organização** lista todas as empresas atingidas. Dados, arquivos/certificado, backups e DNS têm opções independentes. A revisão exige permissão de exclusão, aceite e o nome apresentado.

A operação encerra acessos, sessões, tickets, tokens, integrações e jobs pendentes. Somente tabelas com propriedade comprovada e prefixo confirmado são removidas. Banco criado pelo módulo só é apagado se estiver vazio após essas tabelas; banco manual é preservado. Usuário MySQL gerado é removido quando a exclusão de dados é escolhida. Referências cruzadas com outras estruturas impedem a exclusão física. Arquivos ficam restritos ao diretório empresarial; symlinks não são seguidos. DNS exige registro/ID e marcador de propriedade compatíveis.

Concluído o escopo, o cadastro, os vínculos, as reservas, os planos e o histórico associado são excluídos fisicamente. Não há tombstone nem reativação de um ID removido; o mesmo identificador pode ser usado em um novo cadastro. Recursos que você escolheu preservar ficam fora do catálogo e nunca são adotados ou sobrescritos automaticamente. Em falha, o cadastro e o diagnóstico necessário à retomada permanecem visíveis até a conclusão. A atualização remove os tombstones de exclusões antigas já concluídas, sem apagar recursos externos antes preservados.

## Usuários e troca de empresa

O login continua nativo, inclusive 2FA. No cadastro do colaborador, abra **Permissões → Acesso às empresas**. O administrador principal seleciona a principal e/ou as empresas autorizadas. O vínculo local usa o mesmo e-mail; se necessário, um funcionário local é criado sem privilégios administrativos. As permissões e o cargo continuam definidos em cada empresa.

O administrador de uma empresa secundária pode liberar ou revogar acesso somente a ela. Quando cria uma nova identidade por essa tela, ela não recebe acesso à principal. Se o e-mail já possuir uma identidade central, a senha e os privilégios centrais existentes são preservados; a autenticação continua usando essa identidade.

O seletor aparece acima do perfil nativo e lista apenas empresas autorizadas, inclusive de organizações diferentes quando houver vínculo explícito. Pertencer à organização não libera automaticamente suas outras empresas. No login, a última empresa é revalidada; se o acesso foi removido ou ela está indisponível, o sistema escolhe outra autorizada. Sem nenhuma empresa disponível, informa a restrição.

A troca utiliza POST com CSRF, ticket de uso único e desafio do navegador. Cada empresa conserva banco, arquivos, módulos, sessão e permissões próprias. O retorno à principal exige autorização específica. Requisições AJAX de uma aba antiga da principal recebem conflito de contexto, evitando gravação silenciosa no destino errado.

Na tela nativa **Módulos**, um administrador empresarial pode ativar e desativar pacotes já disponibilizados para aquela empresa. Desativar preserva o pacote e os dados para uma nova ativação. Instalar, remover, atualizar arquivos ou executar manualmente atualização de banco pela tela nativa continua exclusivo da principal; alterações de estado são protegidas por CSRF e validadas no servidor.

Multiempresa pode ser desativado normalmente na principal. Os dados permanecem, e os acessos empresariais param até a reativação. Identidades sem acesso à principal não passam a ver seus dados durante a desativação. Ao desinstalar o código, essas identidades ficam inativas; no modo de preservação, a reinstalação reativa somente as identidades que o próprio módulo suspendeu nessa operação.

## Logs e auditoria

Abra Logs pela navegação, empresa, organização ou execução. Filtre organização, empresa, execução, situação e período (datas do servidor em UTC); a interface mostra o horário local do navegador. Baixar logs exporta JSONL com os mesmos filtros e escopo autorizado, em lotes. Os registros incluem assinaturas de integridade. A exclusão física confirmada também remove o histórico associado ao cadastro excluído; os demais registros permanecem. Eventos de confirmação, mudanças cadastrais, operações SQL/recursos, etapas, certificados, DNS e acesso ficam rastreáveis. Mensagens de bibliotecas que possam conter segredos não são copiadas integralmente.

## Backups, atualizações e cron preservados

O ícone de backup agenda a rotina no cron principal. Backups autenticados incluem dados, anexos e arquivos privados dos módulos/extensões; restauração exige destino vazio e preserva a origem. Guarde também a base central e o diretório privado: eles contêm o cadastro, as credenciais cifradas, os certificados e a chave mestra necessária à recuperação.

Após a atualização nativa do Perfex principal, o módulo reconhece o núcleo instalado e verifica sua correspondência com o banco. Para cada empresa desatualizada, coloca somente aquela empresa em manutenção, cria backup criptografado e executa as migrations nativas. Se a migration falhar, restaura os dados e as tabelas da própria empresa automaticamente, mantendo-a protegida até a próxima tentativa. A retomada ocorre no cron ou próximo acesso; o administrador não precisa cadastrar esquema, fazer backup prévio nem executar SQL. A principal permanece protegida.

O plano de publicação de um diretório separado e a restauração em destino escolhido continuam disponíveis como operações manuais avançadas. Esses planos explícitos mantêm seu protocolo de recuperação; não são requisitos do provisionamento padrão.

`after_cron_run` executa jobs/eventos e o cron empresarial em lotes, com locks e tempo limite. Também expira sessões, tickets de troca e planos não confirmados. Eventos podem ser repetidos após interrupção: processadores devem ser idempotentes.

Em múltiplos servidores, todos precisam da mesma configuração privada, chave mestra, caminhos e armazenamento compartilhado. Grids e logs são paginados; a execução é serializada por recurso. Não foram feitos testes de carga ou homologação dessa topologia. Não há driver S3/MinIO incluído nesta versão.


## Desinstalar o módulo

A opção Remover Multiempresa abre a revisão antes de remover código. **Preservar empresas e dados** desativa o acesso e conserva cadastros, bancos, arquivos, backups e configuração cifrada para reinstalação. **Remover empresas e dados** apresenta as organizações e recursos, exige confirmação e processa as etapas. Ao concluir, elimina também as tabelas e os arquivos privados pertencentes ao módulo. Recursos externos sem propriedade comprovada não são apagados. A instalação principal, seus clientes, funcionários, tabelas, arquivos e demais módulos permanecem. Uma falha mantém o diagnóstico para revisar e continuar; não é apresentada como conclusão.

## Integrações opcionais

Os tokens permitem que sistemas externos leiam dados de uma empresa. Não são necessários para cadastro, provisionamento, CNPJ, CEP ou uso normal do CRM. Marque somente as permissões necessárias:

| Opção da tela | Permissão interna | Acesso concedido |
| --- | --- | --- |
| Identificação da empresa | `context:read` | IDs da empresa, organização e funcionário vinculados ao token |
| Clientes | `clients:read` | Clientes permitidos pelas permissões do funcionário |
| Faturas | `invoices:read` | Faturas permitidas pelas permissões do funcionário |
| Eventos recebidos | `events:read` | Situação de processamento dos eventos de integração da empresa |

Identificação não concede acesso a clientes ou faturas. Nenhuma dessas permissões autoriza escrita. O token só pode ser criado para uma empresa com acesso autorizado. O seletor lateral usa um ícone de empresa interno, independente do favicon e de imagens cadastradas.
