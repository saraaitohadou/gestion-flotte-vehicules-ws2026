<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Garde-fou documentation <-> code : tout code d'erreur effectivement émis par
 * app/ doit apparaître dans l'énumération OpenAPI de components.schemas.Erreur.
 */
class OpenApiErreursDocumenteesTest extends TestCase
{
    private const CODES_RECEMMENT_DOCUMENTES = [
        'CHAUFFEUR_SERVICE_INDISPONIBLE',
        'CHAUFFEUR_INTROUVABLE',
        'CHAUFFEUR_NON_SELECTIONNABLE',
        'GESTIONNAIRE_INTROUVABLE',
    ];

    public function test_tous_les_codes_erreur_em_par_app_sont_documentes_dans_openapi(): void
    {
        $documentes = $this->codesDocumentes();
        $emis       = $this->codesEmisParApp();

        $this->assertNotEmpty($emis, 'Aucun code d\'erreur n\'a pu être extrait de app/.');

        $manquants = array_values(array_diff($emis, $documentes));
        sort($manquants);
        $this->assertSame(
            [],
            $manquants,
            'Codes émis par app/ mais absents de openapi.yaml : ' . implode(', ', $manquants)
        );

        foreach (self::CODES_RECEMMENT_DOCUMENTES as $code) {
            $this->assertContains($code, $emis, "{$code} n'est pas émis par app/.");
            $this->assertContains($code, $documentes, "{$code} n'est pas documenté dans openapi.yaml.");
        }

        // Codes produits par le gestionnaire d'exceptions global (bootstrap/app.php).
        foreach (['VALIDATION_ERREUR', 'RESSOURCE_INTROUVABLE'] as $code) {
            $this->assertContains($code, $documentes, "{$code} n'est pas documenté dans openapi.yaml.");
        }
    }

    /** @return string[] */
    private function codesDocumentes(): array
    {
        $yaml = (string) file_get_contents(dirname(__DIR__, 2) . '/public/openapi.yaml');

        preg_match('/Codes possibles:(.*?)example:/s', $yaml, $bloc);
        $this->assertNotSame('', $bloc[1] ?? '', 'Énumération des codes introuvable dans openapi.yaml.');

        preg_match_all('/\b[A-Z][A-Z0-9_]{2,}\b/', $bloc[1], $trouvailles);

        return array_values(array_unique($trouvailles[0]));
    }

    /** @return string[] */
    private function codesEmisParApp(): array
    {
        $fichiers = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        $codes = [];
        foreach ($fichiers as $fichier) {
            if (! $fichier->isFile() || $fichier->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->codesDansFichier((string) $fichier->getPathname()) as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * Analyse token par token (et non par regex) pour résister aux appels
     * multi-lignes et aux chaînes interpolées. Le 3e argument est le code ;
     * s'il est absent, BusinessException utilise 'BUSINESS_ERROR'.
     *
     * @return string[]
     */
    private function codesDansFichier(string $chemin): array
    {
        $tokens = token_get_all((string) file_get_contents($chemin));
        $codes  = [];
        $total  = count($tokens);

        for ($i = 0; $i < $total; $i++) {
            $jeton = $tokens[$i];
            if (! is_array($jeton) || $jeton[0] !== T_NEW) {
                continue;
            }

            $j = $i + 1;
            while ($j < $total && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $total || ! is_array($tokens[$j])
                || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'BusinessException') {
                continue;
            }

            while ($j < $total && $tokens[$j] !== '(') {
                $j++;
            }
            if ($j >= $total) {
                continue;
            }

            $profondeur = 0;
            $arguments  = [[]];
            for ($k = $j; $k < $total; $k++) {
                $t = $tokens[$k];
                if ($t === '(') {
                    $profondeur++;
                    continue;
                }
                if ($t === ')') {
                    $profondeur--;
                    if ($profondeur === 0) {
                        break;
                    }
                    $arguments[count($arguments) - 1][] = $t;
                    continue;
                }
                if ($t === ',' && $profondeur === 1) {
                    $arguments[] = [];
                    continue;
                }
                if ($profondeur >= 1) {
                    $arguments[count($arguments) - 1][] = $t;
                }
            }

            $code = 'BUSINESS_ERROR';
            if (isset($arguments[2])) {
                foreach ($arguments[2] as $t) {
                    if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }
                    $candidat = trim($t[1], "'\"");
                    if (preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $candidat)) {
                        $code = $candidat;
                    }
                    break;
                }
            }
            $codes[] = $code;
        }

        return $codes;
    }
}
