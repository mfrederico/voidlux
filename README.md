# VoidLux

P2P OpenSwoole Compiler & Graffiti Wall.

VoidLux compiles PHP/OpenSwoole applications into standalone static binaries using [static-php-cli](https://github.com/crazywhalecc/static-php-cli), and demonstrates the concept with a P2P graffiti wall -- 5 instances that discover each other, gossip posts, and sync data via TCP mesh.

## Requirements

- PHP 8.1+
- Swoole extension (`pecl install swoole`)
- SQLite3 PDO extension
- tmux (for the 5-node demo)

## Quick Start

```bash
# Install dependencies
composer install

# Run a single node
php bin/voidlux demo --http-port=8080

# Run the 5-node demo
bash scripts/demo-5-nodes.sh
```

Then open http://localhost:8081 through http://localhost:8085 in your browser.

## CLI Commands

```bash
# Run graffiti wall demo
php bin/voidlux demo [options]

# Detect extensions in a PHP project
php bin/voidlux detect <app-dir>

# Compile to static binary
php bin/voidlux compile <app-dir> --output=./build/myapp
```

### Demo Options

| Option | Default | Description |
|---|---|---|
| `--http-port` | 8080 | HTTP/WebSocket server port |
| `--p2p-port` | 7001 | P2P TCP mesh port |
| `--discovery-port` | 6001 | UDP LAN discovery port |
| `--seeds` | (none) | Comma-separated seed peers (`host:port,...`) |
| `--data-dir` | ./data | SQLite database directory |

## Architecture

### P2P Networking

- **TCP Mesh**: Swoole coroutine TCP server + client connections
- **UDP Broadcast**: LAN peer discovery on `255.255.255.255`
- **Seed Peers**: Static peer list for WAN bootstrap
- **Peer Exchange (PEX)**: Gossip-based peer list sharing every 30s
- **Gossip Engine**: Push-based post dissemination with UUID dedup
- **Anti-Entropy**: Pull-based consistency repair every 60s
- **Lamport Clock**: Logical timestamps for causal ordering

### Wire Protocol

Messages use JSON with a 4-byte uint32 big-endian length prefix:

| Type | Code | Description |
|---|---|---|
| HELLO | 0x01 | Handshake with node ID |
| POST | 0x02 | New graffiti post |
| SYNC_REQ | 0x03 | Request posts since lamport_ts |
| SYNC_RSP | 0x04 | Response with missed posts |
| PEX | 0x05 | Peer exchange |
| PING | 0x06 | Keepalive |
| PONG | 0x07 | Keepalive response |

### Compiler Pipeline

1. **Detect** -- Scan PHP files for required extensions
2. **Resolve** -- Run `composer install --no-dev`, rewrite MYCTOBOT_ROOT paths
3. **Bundle** -- Copy sources to staging, generate bootstrap entry point
4. **Download** -- `spc download` PHP source + extension sources
5. **Build** -- `spc build --build-micro` with detected extensions
6. **Combine** -- `spc micro:combine` into standalone binary

## Compiling to Static Binary

```bash
# Install static-php-cli
bash scripts/install-spc.sh

# Compile the graffiti wall
bash scripts/build-graffiti.sh

# Run the compiled binary
./build/graffiti-wall demo --http-port=8080
```

## Project Structure

```
voidlux/
├── bin/voidlux                     CLI entry point
├── src/
│   ├── Compat/OpenSwooleShim.php   OpenSwoole → Swoole aliases
│   ├── Compiler/                   Static binary compiler
│   ├── Template/                   {{VAR}} template engine
│   ├── P2P/
│   │   ├── PeerManager.php         Peer lifecycle management
│   │   ├── Discovery/              UDP broadcast, seeds, PEX
│   │   ├── Protocol/               Message types, codec, Lamport clock
│   │   ├── Gossip/                 Push gossip + pull anti-entropy
│   │   └── Transport/              TCP mesh + connection wrapper
│   └── App/GraffitiWall/           Demo application
├── templates/                      Compiler templates
├── scripts/                        Demo & build scripts
└── build/                          Compiled output (.gitignored)
```
