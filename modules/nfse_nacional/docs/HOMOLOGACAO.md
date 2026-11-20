# Homologação do módulo NFS-e Nacional

## O que já foi conferido

Os testes locais exercitam assinatura, esquemas, formato da API, tratamento de respostas, idempotência, permissões, isolamento e renderização das telas. O transporte nacional, o banco e a sessão Perfex foram substituídos por simulações quando necessário. Veja TESTES.md.

Na versão **0.4.7**, Arial regular, Arial Bold e Microsoft Sans Serif foram preparadas a partir dos originais fornecidos pelo usuário em **10/11/2026** e incluídas em `vendor/pdf-runtime/fonts/nt008/`. A verificação independente executou **19 suítes e 687 verificações**. Dois DANFSe de ensaio, gerados a partir de XMLs sintéticos, foram conferidos em A4, com uma página cada, e revisados visualmente; `pdffonts` confirmou `ArialMT`, `Arial-BoldMT` e `MicrosoftSansSerif` incorporadas, com subconjuntos e mapas Unicode. Não houve instalação de fontes no Perfex/sistema nem download durante a geração. Esses resultados comprovam o funcionamento local do gerador, sem representar homologação fiscal da emissão.

## Sequência no servidor de testes

1. Instalar ou atualizar em uma cópia de testes do Perfex 3.2.x; conferir tabelas, menu, guia do cliente e seção NFS-e em `admin/settings?group=nfse_nacional`. Na atualização, verificar a preservação do certificado local e das notas anteriores.
2. Manter produção restrita. Conferir o reaproveitamento do A1 disponível para a empresa; importar também um A1 local com a prioridade desmarcada e marcada. Arquivo e senha devem permanecer acessíveis em ambos os casos. Conferir senha incorreta, titular divergente e o formato PKCS#12 efetivamente utilizado no servidor, sem compartilhar senha em mensagens ou registros.
3. Conferir titular/validade, emitente, município, serviço, regime e série disponível.
4. Consultar o convênio municipal.
5. Cadastrar cliente, contato principal e um tomador de teste permitido pelo ambiente. Selecionar serviço, descrição, ISS e IBS/CBS diferentes no perfil do cliente e no tomador; alternar herança, testar zero e campo opcional vazio, salvar, reabrir e verificar a persistência. Clonar, revisar e salvar outro tomador; alterar a cópia e verificar que o original não mudou. Pesquisar serviço por código com/sem pontuação e por palavras com/sem acento.
6. Abrir **Vendas → Faturas**, usar **Emitir NFS-e** diretamente na linha e preparar um rascunho pelo modal. Conferir a ação também depois de ordenar, paginar e pesquisar a grade; cancelar o modal com ESC. Verificar herança tomador → cliente → empresa e identificação dos códigos exclusivos de testes. Repetir o clique deve abrir o mesmo rascunho. Conferir competência, serviço, valores, endereço, identificação e tributação; verificar os dados reaproveitados do cadastro.
7. Validar localmente e transmitir no ambiente de testes. Conferir os erros de negócio até o aceite.
8. Comparar o XML retornado, a consulta oficial e o DANFSe gerado, incluindo campos tributários e as fontes exigidas pela NT 008. Conferir também se o ambiente nacional preserva a representação textual dos campos da DPS; a correlação bloqueia vinculação quando os dados devolvidos diferem dos enviados.
9. Enviar para uma caixa de e-mail controlada pelo operador e conferir os dois anexos. SMTP aceito não é prova de recebimento final.
10. Testar acesso com usuário limitado a um cliente, outra empresa do Multiempresa e duas sessões simultâneas para a mesma fatura. Verificar que as autorizações de acesso ao certificado são respeitadas e que uma mudança do cliente da fatura não vincula a nota anterior ao novo cliente.
11. Confirmar que desativar preserva dados e que o backup recupera o conteúdo com a mesma chave e identidade de empresa.
12. Somente após o aceite dessa homologação, selecionar produção e habilitar sua transmissão nas configurações.

## Dependências da conclusão

- Acesso a uma instalação real do núcleo Perfex e a seu banco MySQL/MariaDB.
- Certificado A1 do emitente, fornecido diretamente ao servidor pela interface.
- Habilitação municipal e enquadramento fiscal correto da operação.
- Aceite da SEFIN para o leiaute do ambiente selecionado.
- SMTP configurado e PHP 8.2 ou superior para o DANFSe. Motor e fontes originais já preparadas acompanham a versão 0.4.7, conforme DANFSE_EMBUTIDO.md, sem instalação adicional de fontes na aplicação. Na homologação, comparar o DANFSe com o XML efetivamente autorizado, conferir a incorporação das famílias e o leiaute da NT 008, e testar o envio dos anexos em caixa controlada.
- Confirmação da seção nativa de Configurações e dos hooks de grade e prévia de fatura no núcleo e tema instalados. O registro moderno usa `app->add_settings_section()` com fallback legado; o núcleo licenciado não estava disponível para executar essa integração.
- Caso exista serviço de A1 no núcleo personalizado, conectar seu contrato ao hook interno documentado em CERTIFICADOS.md. Não há dados suficientes para adivinhar o armazenamento desse núcleo.

Essa sequência não foi executada externamente durante a construção do pacote. Nenhuma nota real foi emitida e nenhum e-mail foi enviado.
