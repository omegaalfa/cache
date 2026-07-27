<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Omegaalfa\Cache\CacheFactory;
use Omegaalfa\Cache\Config\FileConfig;
use Omegaalfa\Cache\Exception\InvalidArgumentException;

function s(string $t): void
{
    echo "\n$t\n", str_repeat('─', 55), "\n";
}

echo "🚀 Iniciando exemplo...\n";
$c = CacheFactory::array();
s('Gravação, hit e miss');
$c->set('usuario-42', ['id' => 42, 'nome' => 'Ana'], 60);
echo "✅ Valor salvo.\n", $c->has('usuario-42') ? "🎯 HIT!\n" : "❌ MISS\n";
echo json_encode($c->get('usuario-42'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
echo "📦 Ausente: ", $c->get('ausente', 'padrão'), "\n";
s('Tipos e bulk');
$c->setMultiple(['falso' => false, 'zero' => 0, 'nulo' => null, 'lista' => ['PHP'], 'float' => 19.9]);
echo json_encode($c->getMultiple(['falso', 'zero', 'nulo', 'float']), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
echo "✅ null é hit: ", $c->has('nulo') ? 'sim' : 'não', "\n";
s('remember');
$n = 0;
$r = static function () use (&$n): array {
    ++$n;
    echo "⚙️ Operação lenta...\n";
    return ['pronto' => true];
};
$c->remember('relatorio', 60, $r);
$c->remember('relatorio', 60, $r);
echo "✅ Resolver executado $n vez.\n";
s('TTL');
$c->set('curta', 'x', 1);
echo "⌛ Aguardando expiração...\n";
sleep(2);
echo $c->has('curta') ? "⚠️ Ainda existe\n" : "✅ Expirou corretamente.\n";
$c->set('infinita', 'sem TTL', null);
s('Delete e clear');
$c->set('remover', 1);
$c->delete('remover');
echo "🗑️ Chave removida.\n";
$c->clear();
echo "🧹 Cache limpo.\n";
s('Namespace FileStore');
$b = sys_get_temp_dir() . '/omegaalfa-cache-exemplo';
$p = CacheFactory::file(new FileConfig($b . '/producao'));
$t = CacheFactory::file(new FileConfig($b . '/teste'));
$p->clear();
$t->clear();
$p->set('ambiente', 'produção');
$t->set('ambiente', 'teste');
echo "📦 ", $p->get('ambiente'), " / ", $t->get('ambiente'), "\n";
$p->clear();
$t->clear();
s('Exceções');
try {
    $c->set('chave/invalida', 'x');
} catch (InvalidArgumentException $e) {
    echo "✅ Capturada: {$e->getMessage()}\n";
}
echo "\n🎉 Exemplo finalizado com sucesso!\n";

$cache = CacheFactory::array();
$cache->set('saudacao', 'Olá!', 60);
echo $cache->get('saudacao', 'MISS');