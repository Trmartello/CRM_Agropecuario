<?php

namespace App\Services;

/**
 * Compressão de fotos no servidor (GD). Foto de celular chega com 4–12 MB;
 * aqui vira JPEG de ~200–400 KB (lado maior 1600 px) + miniatura de 320 px
 * para as listagens — essencial no campo, onde a internet é lenta.
 *
 * A miniatura fica em uploads/miniaturas/<mesmo caminho relativo> e é servida
 * por arquivo/upload&mini=1 (com fallback para o original quando não existe,
 * ex.: fotos antigas). Se a imagem não puder ser decodificada, retorna null e
 * o chamador guarda o arquivo original como veio.
 */
class ImagemService
{
    public const LADO_MAXIMO = 1600;   // px do lado maior da foto guardada
    public const LADO_MINIATURA = 320; // px do lado maior da miniatura
    public const QUALIDADE = 80;       // qualidade JPEG da foto principal
    public const QUALIDADE_MINI = 70;  // qualidade JPEG da miniatura

    /**
     * Comprime a imagem enviada e grava:
     *   uploads/<relativoSemExt>.jpg            (foto principal)
     *   uploads/miniaturas/<relativoSemExt>.jpg (miniatura)
     * Retorna o caminho relativo gravado ("<relativoSemExt>.jpg") ou null.
     */
    public static function comprimirFoto(string $origem, string $relativoSemExt): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null; // GD ausente: segue com o arquivo original
        }
        $binario = @file_get_contents($origem);
        if ($binario === false || $binario === '') {
            return null;
        }
        $img = @imagecreatefromstring($binario);
        if ($img === false) {
            return null; // formato não decodificável (ex.: HEIC que escapou)
        }
        $img = self::corrigirOrientacao($img, $origem);
        $img = self::achatarFundoBranco($img); // transparência de PNG/WebP em JPEG

        $relativo = $relativoSemExt . '.jpg';
        $destino = uploads_dir() . '/' . $relativo;
        self::garantirPasta($destino);
        $principal = self::redimensionar($img, self::LADO_MAXIMO);
        if (!@imagejpeg($principal, $destino, self::QUALIDADE)) {
            return null;
        }

        $miniatura = uploads_dir() . '/miniaturas/' . $relativo;
        self::garantirPasta($miniatura);
        @imagejpeg(self::redimensionar($principal, self::LADO_MINIATURA), $miniatura, self::QUALIDADE_MINI);

        return $relativo;
    }

    /**
     * Comprime uma imagem enviada e devolve [mime, binário] para guardar no
     * banco (ex.: foto do estágio fenológico). Retorna null se não decodificar.
     */
    public static function comprimirParaBlob(string $origem, int $ladoMaximo = 900, int $qualidade = 82): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $binario = @file_get_contents($origem);
        if ($binario === false || $binario === '') {
            return null;
        }
        $img = @imagecreatefromstring($binario);
        if ($img === false) {
            return null;
        }
        $img = self::corrigirOrientacao($img, $origem);
        $img = self::achatarFundoBranco($img);
        $img = self::redimensionar($img, $ladoMaximo);
        ob_start();
        $ok = imagejpeg($img, null, $qualidade);
        $jpeg = ob_get_clean();
        return ($ok && $jpeg !== false && $jpeg !== '') ? ['image/jpeg', $jpeg] : null;
    }

    private static function garantirPasta(string $arquivo): void
    {
        $dir = dirname($arquivo);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** Reduz para o lado maior indicado (nunca amplia). */
    private static function redimensionar(\GdImage $img, int $ladoMaximo): \GdImage
    {
        $largura = imagesx($img);
        $altura = imagesy($img);
        $maior = max($largura, $altura);
        if ($maior <= $ladoMaximo) {
            return $img;
        }
        $novaLargura = (int) round($largura * $ladoMaximo / $maior);
        $reduzida = imagescale($img, max(1, $novaLargura));
        return $reduzida !== false ? $reduzida : $img;
    }

    /** Celular grava a rotação na tag EXIF; sem isso a foto apareceria deitada. */
    private static function corrigirOrientacao(\GdImage $img, string $origem): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($origem);
        $orientacao = (int) ($exif['Orientation'] ?? 1);
        switch ($orientacao) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $img = imagerotate($img, 180, 0) ?: $img;
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($img, IMG_FLIP_VERTICAL);
                $img = imagerotate($img, -90, 0) ?: $img;
                break;
            case 6:
                $img = imagerotate($img, -90, 0) ?: $img;
                break;
            case 7:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                $img = imagerotate($img, -90, 0) ?: $img;
                break;
            case 8:
                $img = imagerotate($img, 90, 0) ?: $img;
                break;
        }
        return $img;
    }

    private static function achatarFundoBranco(\GdImage $img): \GdImage
    {
        $largura = imagesx($img);
        $altura = imagesy($img);
        $plano = imagecreatetruecolor($largura, $altura);
        imagefill($plano, 0, 0, (int) imagecolorallocate($plano, 255, 255, 255));
        imagecopy($plano, $img, 0, 0, 0, 0, $largura, $altura);
        return $plano;
    }
}
