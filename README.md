# Omegaalfa Cache

> Cache previsível para PHP 8.4, compatível com PSR-6 e PSR-16.

## 📚 O que é cache?

Cache é uma cópia temporária de algo caro de obter: uma consulta, API, relatório ou HTML. Um **hit** ocorre quando a chave existe; um **miss**, quando não existe ou expirou. **TTL** é seu tempo de vida. **Backend/Store** é onde os dados ficam; **driver** seleciona esse backend; **namespace** separa aplicações; **serializer** converte valores PHP para armazenamento. PSR-6 e PSR-16 são padrões PHP-FIG para APIs interoperáveis.

> [!IMPORTANT]
> Cache não é a fonte oficial. A aplicação deve continuar correta sem ele.

Use-o em operações repetidas e lentas. Evite-o quando o dado não pode ficar desatualizado ou a operação já é barata.

## ⚡ Recursos

PHP 8.4, PSR-6/16, TTL, `DateInterval`, bulk, `remember()`, namespaces, serializers e backends Array, File, Redis, APCu e Null.

## 📦 Instalação

```bash
composer require omegaalfa/cache
php exemplo.php
```

Redis requer `ext-redis`; APCu requer `ext-apcu`.

## 🚀 Primeiro exemplo

```php
require __DIR__.'/vendor/autoload.php';
use Omegaalfa\Cache\CacheFactory;

$cache = CacheFactory::array();
$cache->set('saudacao', 'Olá!', 60);
echo $cache->get('saudacao', 'MISS');
```

A factory cria memória local, `set()` grava por 60 segundos e `get()` lê ou devolve o padrão.

## 🗄️ Backends disponíveis

| Backend | Persistência | Compartilhado | Uso |
|---|---:|---:|---|
| ArrayStore | ❌ | ❌ | testes/processo curto |
| FileStore | ✅ | um host | persistência local |
| RedisStore | ✅ | ✅ | produção distribuída |
| ApcuStore | depende do SAPI | um host | cache local experimental |
| NullStore | ❌ | ❌ | cache desabilitado |

### ArrayStore

```php
$cache = CacheFactory::array(maxEntries: 1000);
```

Rápido e sem dependências; some ao terminar o processo.

### FileStore

```php
use Omegaalfa\Cache\Config\FileConfig;
$cache = CacheFactory::file(new FileConfig(__DIR__.'/var/cache'));
```

Usa locks, checksum, temporário e `rename()`. Indicado para filesystem local Unix/WSL. Não há `fsync`; NFS e Windows nativo não foram homologados.

### RedisStore

```php
use Omegaalfa\Cache\Config\RedisConfig;
$cache = CacheFactory::redis(new RedisConfig(
    host: '127.0.0.1', password: getenv('REDIS_PASSWORD') ?: null,
    database: 0, timeout: 2.0, readTimeout: 2.0, prefix: 'app:prod:',
));
```

É compartilhado e usa MGET/pipeline. `clear()` versiona o namespace sem FLUSHDB. Não há failover; TLS com certificados ainda é experimental. Também existe `CacheFactory::fromRedis($redis, 'app:')`.

### NullStore

```php
$cache = CacheFactory::null();
```

Aceita gravações e sempre retorna miss.

### ApcuStore — experimental

```php
$cache = CacheFactory::apcu('app:');
```

Hit, miss, TTL, bulk e namespace são testados, mas concorrência/SAPIs precisam de matriz maior. Não compartilha entre hosts. Em CLI: `php -d apc.enable_cli=1 script.php`.

## 🧠 Conceitos e API

```php
$cache->set('segundos', 'valor', 300);
$cache->set('intervalo', 'valor', new DateInterval('PT15M'));
$cache->set('infinito', 'valor', null);
$cache->has('segundos');
$cache->delete('segundos');
$cache->clear();

$cache->setMultiple(['a' => 1, 'b' => 2], 60);
$valores = $cache->getMultiple(['a', 'b', 'c'], null);
$cache->deleteMultiple(['a', 'b']);
```

TTL zero/negativo expira imediatamente. Use prefixos Redis/APCu ou diretórios File distintos como namespaces.

### remember()

```php
$usuario = $cache->remember('usuario-42', 300, fn() => buscarUsuario(42));
```

O resolver roda somente no miss; suas exceções são propagadas. Não há lock contra stampede.

### Tipos, null e valores falsy

```php
$cache->set('string', 'texto');
$cache->set('int', 42);
$cache->set('float', 19.9);
$cache->set('false', false);
$cache->set('zero', 0);
$cache->set('vazio', '');
$cache->set('null', null);
$cache->set('array', ['a']);
$cache->set('objeto', new stdClass());

var_dump($cache->has('null')); // true: diferencia null de miss
```

O `NativeSerializer` é padrão. Para JSON:

```php
use Omegaalfa\Cache\SimpleCache;
use Omegaalfa\Cache\Serializer\JsonSerializer;
use Omegaalfa\Cache\Store\ArrayStore;
$cache = new SimpleCache(new ArrayStore(), new JsonSerializer());
```

Closures/resources falham com `SerializationException`.

### Configuração por array

```php
$cache = CacheFactory::create([
 'driver' => 'file',
 'file' => ['directory' => __DIR__.'/var/cache', 'gcProbability' => 1, 'gcDivisor' => 100],
]);
```

Drivers: `array`, `file`, `redis`, `apcu`, `null`.

### Exceções

```php
use Omegaalfa\Cache\Exception\InvalidArgumentException;
use Omegaalfa\Cache\Exception\BackendUnavailableException;
try {
    $cache->set('chave/invalida', 'x');
} catch (InvalidArgumentException|BackendUnavailableException $e) {
    error_log($e->getMessage());
}
```

Chaves vazias ou com `{}()/\\@:` são inválidas.

## 🎯 Casos reais

```php
$usuarios = $cache->remember('usuarios-ativos', 60, fn() =>
    $pdo->query('SELECT id,nome FROM usuarios WHERE ativo=1')->fetchAll(PDO::FETCH_ASSOC)
);
$cotacao = $cache->remember('cotacao-usd', 30, fn() =>
    json_decode(file_get_contents('https://api.exemplo.test/cotacao'), true, flags: JSON_THROW_ON_ERROR)
);
$relatorio = $cache->remember('relatorio', new DateInterval('PT30M'), fn() => gerarRelatorio());
$config = $cache->remember('config', 300, fn() => json_decode(file_get_contents(__DIR__.'/config.json'), true));
$html = $cache->remember('html-inicial', 120, fn() => renderizarPagina());
$permissoes = $cache->remember('permissoes-42', 60, fn() => carregarPermissoes(42));
```

Após mudar o dado, use `delete()`. Também se podem guardar metadados de arquivos e dados temporários de sessão, mas a biblioteca não gerencia arquivos nem implementa `SessionHandlerInterface`.

## 📖 PSR-6

```php
use Omegaalfa\Cache\CacheItemPool;
use Omegaalfa\Cache\Store\ArrayStore;
$pool = new CacheItemPool(new ArrayStore());
$item = $pool->getItem('produto-42');
if (!$item->isHit()) {
    $item->set(['nome'=>'Monitor'])->expiresAfter(300);
    $pool->save($item);
}
$pool->saveDeferred($pool->getItem('outra')->set('valor'));
$pool->commit();
```

O destrutor não faz commit implícito.

## ✅ Boas práticas

- escolha TTL pela tolerância a dados antigos;
- invalide após escritas;
- separe ambiente/versão por namespace;
- use bulk para muitas chaves;
- mantenha senhas fora do código;
- monitore backend e teste hit, miss e falha;
- nunca use cache como única persistência.

## ❌ Erros comuns

| Erro | Solução |
|---|---|
| confundir null e miss | use `has()` |
| esperar persistência do ArrayStore | use File/Redis |
| nunca invalidar | combine TTL e `delete()` |
| esperar fallback Redis | capture exceção e defina política |
| usar APCu entre hosts | use Redis |
| guardar closure/resource | armazene dados serializáveis |

## ⚠️ Limitações atuais e roadmap

Não fazem parte da versão estável: locks distribuídos, stampede, failover, L1/L2, tags e métricas. Redis TLS ainda carece de integração com certificados; FileStore não tem `fsync`; APCu permanece experimental para concorrência/SAPI.

Roadmap: locks/stampede, failover explícito, métricas, L1/L2, igbinary/msgpack, tags, Redis TLS e matriz APCu ampliada.

## ❓ FAQ

**Preciso de Redis?** Não: Array atende um processo e File um host.

**Expira sozinho?** Após o TTL produz miss; a remoção física depende do backend.

**Se Redis cair?** Lança `RedisConnectionException`, sem fallback silencioso.

**Funciona em CLI/Docker?** Sim, com extensões, rede e permissões corretas.

**Qual backend?** Array/processo; File/host; Redis/distribuído; APCu/local experimental; Null/desabilitado.

## 🧪 Qualidade

```bash
composer validate --strict
composer style
composer phpstan
composer test
composer test:coverage
```

## 📄 Licença

MIT. Consulte [LICENSE](LICENSE), [CONTRIBUTING.md](CONTRIBUTING.md) e [SECURITY.md](SECURITY.md).
