<?php
declare(strict_types=1);
namespace NfseNacional;

/** Only fixed, application-owned event diagnostics; never XML, identifiers or upstream content. */
final class FiscalEventException extends \InvalidArgumentException {}
