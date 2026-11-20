<?php
// Synthetic documents for CLI tests only.
if (PHP_SAPI !== 'cli') { exit; }
function nationalNote(int $number, string $provider, string $recipient, string $env = '2', string $competence = '2026-11-10', string $issued = '2026-12-01T00:01:00-03:00'): array {
    $key = '2927408' . '2' . (strlen($provider) === 11 ? '1' : '2') . str_pad($provider, 14, '0', STR_PAD_LEFT) . str_pad((string) $number, 13, '0', STR_PAD_LEFT) . '2611' . '123456789' . '1';
    $dpsId = 'DPS2927408' . (strlen($provider) === 11 ? '1' : '2') . str_pad($provider, 14, '0', STR_PAD_LEFT) . '00001' . str_pad((string) $number, 15, '0', STR_PAD_LEFT);
    $providerTag = strlen($provider) === 11 ? 'CPF' : 'CNPJ'; $recipientTag = strlen($recipient) === 11 ? 'CPF' : 'CNPJ';
    $xml = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01"><infNFSe Id="NFS' . $key . '">'
        . '<nNFSe>' . $number . '</nNFSe><cStat>100</cStat><dhProc>2026-12-01T00:02:00-03:00</dhProc><nDFSe>123</nDFSe>'
        . '<emit><' . $providerTag . '>' . $provider . '</' . $providerTag . '><xNome>Prestador ' . $number . '</xNome><enderNac><cMun>2927408</cMun><UF>BA</UF></enderNac></emit>'
        . '<DPS versao="1.01"><infDPS Id="' . $dpsId . '"><tpAmb>' . $env . '</tpAmb><dhEmi>' . $issued . '</dhEmi><serie>1</serie><nDPS>' . $number . '</nDPS><dCompet>' . $competence . '</dCompet><tpEmit>1</tpEmit><cLocEmi>2927408</cLocEmi>'
        . '<prest><' . $providerTag . '>' . $provider . '</' . $providerTag . '><regTrib><opSimpNac>1</opSimpNac><regEspTrib>0</regEspTrib></regTrib></prest>'
        . '<toma><' . $recipientTag . '>' . $recipient . '</' . $recipientTag . '><xNome>Tomador ' . $number . '</xNome></toma>'
        . '<serv><locPrest><cLocPrestacao>2927408</cLocPrestacao></locPrest><cServ><cTribNac>010101</cTribNac><xDescServ>Serviço fictício offline</xDescServ></cServ></serv>'
        . '<valores><vServPrest><vServ>100.00</vServ></vServPrest><trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>1</tpRetISSQN></tribMun><totTrib><indTotTrib>0</indTotTrib></totTrib></trib></valores>'
        . '</infDPS></DPS></infNFSe></NFSe>';
    return ['nsu' => (string) $number, 'type' => 'nfse', 'access_key' => $key, 'xml' => $xml, 'event_type' => '', 'generated_at' => '2026-12-01T00:02:00-03:00'];
}
