package main

import (
	"bufio"
	"context"
	"io"
	"log"
	"net/http"
	"strconv"
	"time"

	"github.com/google/gopacket"
	"github.com/google/gopacket/layers"
	"github.com/google/gopacket/pcap"
	"github.com/google/gopacket/tcpassembly"
	"github.com/google/gopacket/tcpassembly/tcpreader"
)

const httpPort = layers.TCPPort(80)

// CaptureHTTP passively sniffs a service's tunnel interface for plaintext
// (port 80) HTTP traffic and reconstructs full requests. It never touches
// HTTPS/443 traffic -- that's TLS-encrypted and out of scope, by design
// (see the DNS-based domain visibility instead for HTTPS).
//
// Best-effort by design: pipelined/chunked/out-of-order edge cases may not
// reconstruct perfectly. That's an accepted v1 tradeoff, not a bug -- a
// fully robust HTTP/1.1 reassembly implementation is a much larger effort
// than this capture agent's scope justifies for a personal panel.
func CaptureHTTP(ctx context.Context, service ServiceInfo, store *Store, usersFn func() map[string]UserInfo) {
	handle, err := pcap.OpenLive(service.InterfaceName, 65536, false, pcap.BlockForever)
	if err != nil {
		log.Printf("http[%d]: could not open interface %s (retrying in 30s): %v", service.ID, service.InterfaceName, err)
		time.Sleep(30 * time.Second)

		return
	}
	defer handle.Close()

	if err := handle.SetBPFFilter("tcp port 80"); err != nil {
		log.Printf("http[%d]: failed to set BPF filter: %v", service.ID, err)

		return
	}

	streamFactory := &httpStreamFactory{
		serviceID: service.ID,
		store:     store,
		usersFn:   usersFn,
	}
	pool := tcpassembly.NewStreamPool(streamFactory)
	assembler := tcpassembly.NewAssembler(pool)

	packetSource := gopacket.NewPacketSource(handle, handle.LinkType())
	packets := packetSource.Packets()

	ticker := time.NewTicker(time.Minute)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case packet, ok := <-packets:
			if !ok {
				return
			}

			tcpLayer := packet.Layer(layers.LayerTypeTCP)
			if tcpLayer == nil || packet.NetworkLayer() == nil {
				continue
			}

			tcp, _ := tcpLayer.(*layers.TCP)
			assembler.AssembleWithTimestamp(
				packet.NetworkLayer().NetworkFlow(),
				tcp,
				packet.Metadata().Timestamp,
			)
		case <-ticker.C:
			// Flushes streams that have been idle a while, so a
			// half-closed/abandoned connection doesn't hold memory
			// forever.
			assembler.FlushOlderThan(time.Now().Add(-2 * time.Minute))
		}
	}
}

type httpStreamFactory struct {
	serviceID int64
	store     *Store
	usersFn   func() map[string]UserInfo
}

// New is called by tcpassembly once per unidirectional side of a TCP flow --
// a single connection produces two calls, one per direction.
func (f *httpStreamFactory) New(netFlow, transport gopacket.Flow) tcpassembly.Stream {
	reader := tcpreader.NewReaderStream()

	// gopacket renders a TCP gopacket.Flow's endpoints as their plain
	// decimal port number.
	dstPort, err := strconv.Atoi(transport.Dst().String())

	// Only the client->server direction (destination port 80) carries
	// HTTP *requests*; the reverse direction carries the response, which
	// isn't what we want to parse here.
	if err == nil && layers.TCPPort(dstPort) == httpPort {
		sourceIP := netFlow.Src().String()
		go f.parseRequests(&reader, sourceIP)
	} else {
		go tcpreader.DiscardBytesToEOF(&reader)
	}

	return &reader
}

func (f *httpStreamFactory) parseRequests(stream io.Reader, sourceIP string) {
	bufReader := bufio.NewReader(stream)

	for {
		req, err := http.ReadRequest(bufReader)
		if err != nil {
			// Normal end-of-stream (io.EOF / closed connection) as
			// well as a request we couldn't parse -- either way,
			// there's nothing more to read from this stream.
			return
		}

		body, _ := io.ReadAll(io.LimitReader(req.Body, 1<<20)) // cap at 1MB to bound memory
		req.Body.Close()

		users := f.usersFn()
		user, ok := users[sourceIP]
		if !ok || !user.LoggingEffective {
			continue
		}

		headers := map[string]string{}
		for name := range req.Header {
			headers[name] = req.Header.Get(name)
		}

		f.store.Enqueue(TrafficLogRow{
			ServiceID:     f.serviceID,
			ServiceUserID: nullableID(user.ID),
			Kind:          "http",
			OccurredAt:    time.Now().UTC(),
			SourceIP:      sourceIP,
			Host:          req.Host,
			Detail: map[string]any{
				"method":  req.Method,
				"path":    req.URL.RequestURI(),
				"headers": headers,
				"body":    string(body),
			},
		})
	}
}
