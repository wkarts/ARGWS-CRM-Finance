# Verificações da versão 0.8.1

- 574 arquivos PHP analisados sem erros de sintaxe; JavaScript conferido com `node --check`.
- 17 suítes selecionadas aprovadas: alíquotas/credenciais, worker da fila, serviço de emissão, destinatários/Mailer, views de destinatários, autenticação por ambiente, resolução de certificado, hooks e permissões, tomador por fatura, contexto da fatura, sequência de migrações, aviso IBPT, tabelas anteriores, catálogo NBS, estimativas fiscais, isolamento e transporte da interface.
- Worker real com dependências simuladas: 22 verificações, incluindo espera, pagamento parcial/integral, emissão manual, resultado incerto, cancelamento/exclusão, revogação do administrador, mudança de ambiente/emitente, seleção na criação e bloqueio por falta de competência.
- Serviço real: 112 verificações, incluindo ausência de alíquota sem chamada HTTP e presença do percentual do mês no XML assinado e autorizado por uma resposta simulada.
- Grid e alíquota variável: 12 verificações DOM com o JavaScript real, incluindo mais de dez contatos, busca, inclusão, edição, cancelamento, remoção, CSRF e conteúdo escapado.
- As suítes DOM herdadas de endereço, estimativa IBPT, prévia, navegação e pacote mensal também foram aprovadas.

O runtime utilizado foi PHP WebAssembly, com fuso UTC para os certificados sintéticos. Não houve acesso a certificados reais, SEFIN, SMTP ou ao banco do CRM. Os ensaios de resolução que dependem de uma instalação externa do Multiempresa foram pulados pelo próprio teste. O navegador Chromium local não iniciou devido ao bloqueio de sockets do ambiente; não se afirma homologação visual do portal.

A execução ampla inicial encontrou testes herdados dependentes de processos PHP nativos, servidor HTTP/loopback ou OpenSSL externo que não executam neste runtime. A asserção de PDF com IBPT indisponível também falha no pacote-base 0.8.0 original, reproduzida separadamente sem as alterações desta versão. A suíte DOM de gestão IBPT possui asserções antigas incompatíveis com os próprios controles do pacote-base. Essas pendências não foram removidas nem tratadas como testes aprovados.

As verificações locais não substituem a atualização e validação em homologação do Perfex instalado. Nenhuma instalação ou emissão real foi realizada nesta execução.
