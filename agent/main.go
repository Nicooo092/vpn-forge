// Command agent is the vpn-forge capture agent: one process, run as its own
// systemd service, that watches every VPN service the panel knows about and
// records DNS-visible domains and full plaintext HTTP requests into the
// same database Filament reads from.
//
// It never touches the `services`/`service_users` schema (Laravel
// migrations own that) and connects with its own least-privileged DB
// credential (INSERT/SELECT on traffic_logs, SELECT on services and
// service_users only).
package main

import (
	"context"
	"flag"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	configPath := flag.String("config", "/etc/vpnforge/agent.yml", "path to the agent's YAML config file")
	flag.Parse()

	cfg, err := LoadConfig(*configPath)
	if err != nil {
		log.Fatalf("agent: %v", err)
	}

	store, err := NewStore(cfg.DSN())
	if err != nil {
		log.Fatalf("agent: could not connect to the database: %v", err)
	}
	defer store.Close()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigCh
		log.Println("agent: shutting down...")
		cancel()
	}()

	go store.FlushLoop(ctx, 5*time.Second)

	watcher := NewServiceWatcher(store, cfg)
	log.Println("agent: started, watching for services...")
	watcher.Run(ctx)

	log.Println("agent: stopped")
}
