<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Permissoes;
use App\Services\ImagemService;

/**
 * Administração da fenologia (Configurações): o administrador cadastra, por
 * cultura, as fases (código, nome, janela DAP, macrofase, características)
 * e uma FOTO/ARTE personalizada por fase. Sem imagem personalizada, o app
 * usa a ilustração padrão do sistema (SVG gerado no navegador) — o botão
 * "imagem padrão" descarta a personalizada e volta ao desenho.
 */
class FenologiaController
{
    private const PERFIS = ['Administrador'];

    /** Lista os estágios de uma cultura, com os manejos de cada um (painel de Configurações). */
    public function estagios(): void
    {
        Permissoes::exigir(self::PERFIS);
        $culturaId = (int) ($_GET['cultura_id'] ?? 0);
        $estagios = Database::todos(
            'SELECT id, codigo, nome, dias_inicio, dias_fim, descricao, ordem, grupo, caracteristicas,
                    (imagem IS NOT NULL) AS tem_imagem
               FROM fenologia_estagios WHERE cultura_id = ? ORDER BY ordem, dias_inicio',
            [$culturaId]
        );
        foreach ($estagios as &$e) {
            $e['manejos'] = Database::todos(
                'SELECT m.id, m.titulo, m.familia_id, f.nome AS familia, m.orientacao
                   FROM manejos_fase m LEFT JOIN familias_produto f ON f.id = m.familia_id
                  WHERE m.estagio_id = ? ORDER BY m.id',
                [(int) $e['id']]
            );
        }
        unset($e);
        json_ok(['estagios' => $estagios]);
    }

    /** Cria/atualiza uma recomendação técnica (manejo) da fase. */
    public function salvarManejo(): void
    {
        Permissoes::exigir(self::PERFIS);
        $id = (int) ($_POST['id'] ?? 0);
        $estagioId = (int) ($_POST['estagio_id'] ?? 0);
        $titulo = trim($_POST['titulo'] ?? '');
        if ($estagioId <= 0 || $titulo === '') {
            json_erro('Informe a fase e o título da recomendação.');
        }
        $campos = [
            $estagioId,
            mb_substr($titulo, 0, 160),
            (int) ($_POST['familia_id'] ?? 0) ?: null,
            trim($_POST['orientacao'] ?? '') !== '' ? mb_substr(trim($_POST['orientacao']), 0, 500) : null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE manejos_fase SET estagio_id=?, titulo=?, familia_id=?, orientacao=? WHERE id=?',
                array_merge($campos, [$id])
            );
        } else {
            Database::executar(
                'INSERT INTO manejos_fase (estagio_id, titulo, familia_id, orientacao, eh_checklist) VALUES (?,?,?,?,1)',
                $campos
            );
            $id = Database::ultimoId();
        }
        auditar('salvar', 'manejo_fase', $id, $titulo);
        json_ok(['id' => $id]);
    }

    /** Exclui uma recomendação (as marcações históricas de checklist dela caem juntas). */
    public function excluirManejo(): void
    {
        Permissoes::exigir(self::PERFIS);
        $id = (int) ($_POST['id'] ?? 0);
        Database::executar('DELETE FROM manejos_fase WHERE id = ?', [$id]);
        auditar('excluir', 'manejo_fase', $id);
        json_ok();
    }

    /**
     * Insere na fase as recomendações PADRÃO do sistema (sugeridas pelo app)
     * que ainda não existirem — sem apagar as personalizadas do administrador.
     */
    public function manejosPadrao(): void
    {
        Permissoes::exigir(self::PERFIS);
        $estagioId = (int) ($_POST['estagio_id'] ?? 0);
        $estagio = Database::um('SELECT id, cultura_id, codigo FROM fenologia_estagios WHERE id = ?', [$estagioId]);
        if (!$estagio) {
            json_erro('Fase não encontrada.', 404);
        }
        $padroes = \App\Services\FenologiaService::MANEJOS_PADRAO[(int) $estagio['cultura_id'] . ':' . $estagio['codigo']] ?? [];
        if (!$padroes) {
            json_erro('O sistema não tem recomendações padrão para esta fase — cadastre as suas.');
        }
        $inseridos = 0;
        foreach ($padroes as [$titulo, $familiaId, $orientacao]) {
            $existe = Database::valor(
                'SELECT 1 FROM manejos_fase WHERE estagio_id = ? AND titulo = ?', [$estagioId, $titulo]
            );
            if ($existe) {
                continue;
            }
            Database::executar(
                'INSERT INTO manejos_fase (estagio_id, titulo, familia_id, orientacao, eh_checklist) VALUES (?,?,?,?,1)',
                [$estagioId, $titulo, $familiaId, $orientacao]
            );
            $inseridos++;
        }
        auditar('salvar', 'manejo_fase', $estagioId, "padrões do sistema ({$inseridos})");
        json_ok(['inseridos' => $inseridos]);
    }

    /** Cria/atualiza um estágio; aceita foto/arte em multipart (campo "imagem"). */
    public function salvarEstagio(): void
    {
        Permissoes::exigir(self::PERFIS);
        $id = (int) ($_POST['id'] ?? 0);
        $culturaId = (int) ($_POST['cultura_id'] ?? 0);
        $codigo = trim($_POST['codigo'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $ini = (int) ($_POST['dias_inicio'] ?? 0);
        $fim = (int) ($_POST['dias_fim'] ?? 0);
        if ($culturaId <= 0 || $codigo === '' || $nome === '') {
            json_erro('Informe cultura, código e nome da fase.');
        }
        if ($fim < $ini || $ini < 0) {
            json_erro('Janela de dias inválida (início ≤ fim, em dias após o plantio).');
        }
        $campos = [
            $culturaId, mb_substr($codigo, 0, 12), mb_substr($nome, 0, 120), $ini, $fim,
            trim($_POST['descricao'] ?? '') ?: null,
            (int) ($_POST['ordem'] ?? 0),
            trim($_POST['grupo'] ?? '') ?: null,
            trim($_POST['caracteristicas'] ?? '') ?: null,
        ];
        if ($id > 0) {
            Database::executar(
                'UPDATE fenologia_estagios SET cultura_id=?, codigo=?, nome=?, dias_inicio=?, dias_fim=?,
                        descricao=?, ordem=?, grupo=?, caracteristicas=? WHERE id=?',
                array_merge($campos, [$id])
            );
        } else {
            Database::executar(
                'INSERT INTO fenologia_estagios (cultura_id, codigo, nome, dias_inicio, dias_fim, descricao, ordem, grupo, caracteristicas)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                $campos
            );
            $id = Database::ultimoId();
        }

        // Foto/arte personalizada (opcional): comprimida e guardada no banco
        $aviso = null;
        if (!empty($_FILES['imagem']['name'] ?? null) && is_uploaded_file($_FILES['imagem']['tmp_name'] ?? '')) {
            $blob = ImagemService::comprimirParaBlob($_FILES['imagem']['tmp_name']);
            if ($blob === null) {
                $aviso = 'Fase salva, mas a imagem não pôde ser lida (envie JPEG/PNG/WebP).';
            } else {
                Database::executar(
                    'UPDATE fenologia_estagios SET imagem = ?, imagem_mime = ? WHERE id = ?',
                    [$blob[1], $blob[0], $id]
                );
            }
        }
        auditar('salvar', 'fenologia_estagio', $id, "cultura #{$culturaId} · {$codigo}");
        json_ok(['id' => $id, 'aviso' => $aviso]);
    }

    /** Volta a fase para a ilustração padrão do sistema (descarta a foto/arte). */
    public function imagemPadrao(): void
    {
        Permissoes::exigir(self::PERFIS);
        $id = (int) ($_POST['id'] ?? 0);
        Database::executar('UPDATE fenologia_estagios SET imagem = NULL, imagem_mime = NULL WHERE id = ?', [$id]);
        auditar('restaurar', 'fenologia_estagio', $id, 'imagem padrão do sistema');
        json_ok();
    }

    /** Exclui a fase (manejos e marcações de checklist caem em cascata). */
    public function excluirEstagio(): void
    {
        Permissoes::exigir(self::PERFIS);
        $id = (int) ($_POST['id'] ?? 0);
        Database::executar('DELETE FROM fenologia_estagios WHERE id = ?', [$id]);
        auditar('excluir', 'fenologia_estagio', $id);
        json_ok();
    }
}
