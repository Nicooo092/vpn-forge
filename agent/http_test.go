package main

import (
	"fmt"
	"net/http"
	"strings"
	"testing"
)

// Plaintext HTTP requests are captured verbatim for domain visibility, but the
// credential-bearing headers must never be stored in the clear: captured from
// port 80, an Authorization bearer token or a session Cookie would otherwise be
// replayable by anyone with panel or backup access.
func TestSanitizeHeadersRedactsCredentials(t *testing.T) {
	header := http.Header{}
	header.Set("Host", "example.com")
	header.Set("User-Agent", "curl/8.5.0")
	header.Set("Authorization", "Bearer sk-secret-token-value")
	header.Set("Cookie", "session=deadbeefdeadbeef")

	out := sanitizeHeaders(header)

	if got := out["User-Agent"]; got != "curl/8.5.0" {
		t.Errorf("User-Agent should pass through unchanged, got %q", got)
	}

	for _, name := range []string{"Authorization", "Cookie"} {
		want := fmt.Sprintf("[redacted %d bytes]", len(header.Get(name)))
		if out[name] != want {
			t.Errorf("%s should be redacted to %q, got %q", name, want, out[name])
		}
	}

	// The secrets must not survive anywhere in the output values.
	for name, value := range out {
		if strings.Contains(value, "sk-secret-token-value") || strings.Contains(value, "deadbeefdeadbeef") {
			t.Errorf("redacted secret leaked through header %q: %q", name, value)
		}
	}
}

func TestSanitizeHeadersIsCaseInsensitive(t *testing.T) {
	// Go canonicalises header names on Set, but the redaction lookup must not
	// depend on that -- a raw map key of any case must still be redacted.
	header := http.Header{}
	header["COOKIE"] = []string{"session=zzz"}

	if out := sanitizeHeaders(header); out["COOKIE"] == "session=zzz" {
		t.Errorf("an upper-case Cookie variant was stored in the clear: %q", out["COOKIE"])
	}
}
