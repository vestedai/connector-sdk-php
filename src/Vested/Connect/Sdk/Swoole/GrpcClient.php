<?php

declare(strict_types=1);

namespace Vested\Connect\Sdk\Swoole;

use Swoole\Coroutine\Http2\Client as Http2Client;
use Swoole\Http2\Request;
use Vested\Connect\Sdk\Exception\ConnectorException;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\ConnectorMsg;
use Vested\Connect\Sdk\Generated\Proto\Vested\V1\HubMsg;

/**
 * Bidi gRPC over Swoole's HTTP/2 client.
 *
 * Replaces v0.1's HubClient (which used ext-grpc's BidiStreamingCall).
 * Manages the stream lifecycle:
 *   1. open() — TLS connect + open a stream with gRPC headers
 *   2. send($msg) — frame + write DATA
 *   3. recv($timeout) — read one DATA frame + decode HubMsg
 *   4. close() — half-close + close the connection
 *
 * Liveness: the stream dies in two shapes Swoole will not report cleanly —
 * a graceful FIN (read() returns false with errCode 0, connected still
 * true) and a half-open socket (no FIN, no RST, reads time out forever).
 * recv() defends against both: an errCode-0 read that returns in a
 * fraction of its window is a silent EOF and throws immediately, and a
 * liveness deadline (default 60 s — three missed 20 s HeartbeatAck
 * cycles) throws on any stream without inbound frames for that long.
 * The supervisor then redials. See recv() for the incident that produced
 * this (2026-11-24).
 *
 * The http2 client is injected (defaults to Swoole's real client). Tests
 * pass a fake; production passes new Http2Client(...).
 */
final class GrpcClient
{
    private object $http2;
    private int $streamId = 0;
    private string $readBuffer = '';

    /**
     * Wall-clock time of the last inbound frame parsed by recv() — or of
     * open() itself, which is what the clock starts from. Null until the
     * stream is open. Feeds the liveness deadline in recv(); see there for
     * why a quiet stream is a DEAD stream, not a slow one.
     */
    private ?float $lastInboundAt = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $token,
        private readonly bool $insecure = false,
        private readonly int $connectTimeoutSeconds = 10,
        ?object $http2 = null,
        /**
         * Maximum seconds this stream may go without ANY inbound frame
         * before recv() declares it dead. 60 = 3x the 20 s heartbeat
         * interval (HeartbeatTimer): the hub acks every heartbeat, so a
         * healthy stream has inbound traffic far more often than this,
         * and a stream quiet for three missed acks is dead whether the
         * peer sent a FIN that Swoole reports as a silent EOF, or the
         * socket went half-open with no FIN and no RST at all.
         */
        private readonly float $livenessDeadlineSeconds = 60.0,
    ) {
        // The token becomes an HTTP/2 header field value, where CR, LF and NUL
        // are illegal. A server rejects the whole stream with PROTOCOL_ERROR
        // before dispatching the RPC, so the failure surfaces only as "did not
        // receive HelloAck" with nothing logged on either side — the token is
        // correct, and every check of it says so.
        //
        // Refused here, at the one place the header is built, rather than
        // trimmed silently: a token carrying a newline means whatever produced
        // it is wrong (almost always a secret mounted from a file), and saying
        // so once beats making every caller wonder why their credential does
        // not work. WorkerCommand trims the common case before this ever runs.
        if (strcspn($this->token, "\r\n\0") !== strlen($this->token)) {
            throw new ConnectorException(
                'the connector token contains a newline or NUL, which is not a legal HTTP/2 header value. '
                .'A secret mounted with `kubectl create secret --from-file` keeps the file\'s trailing newline — '
                .'strip it, or use --from-literal.'
            );
        }

        $this->http2 = $http2 ?? $this->makeRealClient();
    }

    private function makeRealClient(): Http2Client
    {
        $c = new Http2Client($this->host, $this->port, ! $this->insecure);
        $c->set([
            'timeout'        => $this->connectTimeoutSeconds,
            'open_eof_check' => true,
            // NO keepalive option exists here, and 'http2_keep_alive_pings'
            // must not be added back: Swoole 5.x and 6.x silently ignore it
            // (verified against the swoole-src trees — it is not a socket
            // option and the HTTP/2 client has no ping timer). Liveness is
            // enforced by the application layer instead: the liveness
            // deadline in recv() plus the hub's HeartbeatAck cadence.
        ]);
        return $c;
    }

    public function open(): void
    {
        if (! $this->http2->connect()) {
            throw new ConnectorException("HTTP/2 connect to {$this->host}:{$this->port} failed");
        }

        // Open a stream. The "request" headers double as the gRPC headers.
        $req = $this->makeStreamRequest();
        $streamId = $this->http2->send($req);
        if ($streamId === false) {
            throw new ConnectorException('failed to open gRPC stream');
        }
        $this->streamId = (int) $streamId;
        // Liveness clock starts when the stream is live: the first inbound
        // frame (HelloAck) is due well inside the deadline, so a hub that
        // accepts the stream but never answers is caught by the deadline
        // too, not only by the handshake's own 10 s waits.
        $this->lastInboundAt = microtime(true);
    }

    private function makeStreamRequest(): Request
    {
        $req = new Request();
        $req->method = 'POST';
        $req->path = '/vested.v1.ConnectorHub/Connect';
        $req->headers = [
            ':path'             => '/vested.v1.ConnectorHub/Connect',
            ':method'           => 'POST',
            'content-type'      => 'application/grpc+proto',
            'te'                => 'trailers',
            'x-connector-token' => $this->token,
            'grpc-encoding'     => 'identity',
        ];
        $req->pipeline = true;  // keep the stream open after send()
        return $req;
    }

    /**
     * Max HTTP/2 DATA frame payload we emit per write() call.
     *
     * gRPC servers advertise SETTINGS_MAX_FRAME_SIZE = 16384 (the HTTP/2
     * default/minimum) and REJECT any larger incoming frame with a
     * connection-level FRAME_SIZE_ERROR. Swoole's Http2\Client::write() sends
     * whatever it's given as a SINGLE DATA frame with no chunking (see
     * swoole-src Client::write_data), so a message above 16KB — a Register
     * declaration (observed 88.6KB) or a large ROWSET page — goes out as one
     * oversized frame and the peer resets the connection ("Hello succeeds, then
     * the stream resets"). A TLS-terminating proxy in front of the hub masks
     * this by re-framing; a direct h2c hub does not. We chunk here so every
     * emitted frame stays within the limit — gRPC reassembles the
     * length-prefixed message across DATA-frame boundaries. 16384 is legal to
     * send (the peer's limit is "MUST NOT exceed").
     */
    private const MAX_DATA_FRAME_BYTES = 16384;

    public function send(ConnectorMsg $msg): void
    {
        $payload = $msg->serializeToString();
        $framed  = Frame::encode($payload);

        // Emit as one or more <= MAX_DATA_FRAME_BYTES DATA frames on the stream
        // (do/while so a message that already fits sends exactly one frame,
        // preserving the single-write behavior for small frames).
        $total  = strlen($framed);
        $offset = 0;
        do {
            $chunk = substr($framed, $offset, self::MAX_DATA_FRAME_BYTES);
            if (! $this->http2->write($this->streamId, $chunk)) {
                throw new ConnectorException('gRPC stream write failed');
            }
            $offset += strlen($chunk);
        } while ($offset < $total);
    }

    private bool $closed = false;

    /** True once the stream has been observed to close (vs just timing out). */
    public function isClosed(): bool { return $this->closed; }

    /**
     * Read one HubMsg from the stream. Returns null on timeout — caller
     * should call again. Throws ConnectorException on stream close.
     * Distinguishing the two cases matters: the steady-state loop wants
     * to keep polling on timeout but exit on close.
     */
    public function recv(float $timeoutSeconds = 30.0): ?HubMsg
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $framed = $this->tryParseFrame();
            if ($framed !== null) {
                $msg = new HubMsg();
                $msg->mergeFromString($framed);
                $this->lastInboundAt = microtime(true);
                return $msg;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) return null;  // timeout

            // LIVENESS DEADLINE. Swoole's HTTP/2 client cannot distinguish
            // "no data yet" from "the stream is over" in one case that
            // production actually hit: a graceful peer FIN (hub idle-close,
            // hub restart, relay restart). read() then returns false with
            // errCode 0 while connected stays true — byte-for-byte identical
            // to an idle timeout — and a half-open socket (peer gone, no
            // FIN, no RST) is invisible to the socket layer at all. On both
            // paths the steady-state loop would poll forever: one connector
            // burned 100% CPU for SIX DAYS this way (2026-11-24, mkt-agents;
            // socket stuck in CLOSE_WAIT, no logs, no reconnect, until the
            // pod was restarted). Verified on the swoole-src v5.1.8 tree AND
            // on master: recv_packet_with_length_protocol() returns 0 on
            // recv()<=0 and sets no error — so no dependency upgrade fixes
            // it, and the detection has to live here, at the application
            // layer.
            //
            // The hub acks every one of our 20 s heartbeats with a
            // HeartbeatAck, so any healthy stream has inbound frames far
            // more often than the deadline (default 60 s = three missed
            // acks). A stream quiet for that long is dead by any definition;
            // throwing lets the Daemon end the session and the supervisor
            // redial.
            if ($this->lastInboundAt !== null
                && (microtime(true) - $this->lastInboundAt) > $this->livenessDeadlineSeconds
            ) {
                $this->closed = true;
                throw new ConnectorException(sprintf(
                    'no inbound hub frames for %.0fs — stream presumed dead (closing to reconnect)',
                    microtime(true) - $this->lastInboundAt,
                ));
            }

            // The window actually requested from read(). The fast path below
            // needs it: an idle timeout can never return sooner than the
            // window it was given, but a shrunken tail-window (a partial
            // frame consumed most of the budget) must not be mistaken for
            // an EOF just because it is short.
            $readWindow = $remaining;
            $readStart  = microtime(true);
            $response = $this->http2->read($remaining);
            if ($response === false) {
                // Distinguish timeout from stream close. read() returns false in
                // BOTH cases; we differentiate by errCode + the client's connected
                // flag. ETIMEDOUT (errno 60 on macOS, 110 on Linux) is the *normal*
                // outcome of a timed read with no data — must not be treated as close,
                // otherwise the steady-state polling loop kills the stream on its
                // first idle poll.
                $errCode = (int) ($this->http2->errCode ?? 0);
                $isTimeout = ($errCode === 0 || $errCode === 60 || $errCode === 110);
                $stillConnected = (bool) ($this->http2->connected ?? false);
                if (! $isTimeout || ! $stillConnected) {
                    $this->closed = true;
                    throw new ConnectorException("gRPC stream closed" . ($errCode ? " (errCode={$errCode})" : ""));
                }
                // errCode 0 with the socket still "connected" is AMBIGUOUS:
                // a genuine idle timeout OR the silent EOF described above.
                // The discriminator is the requested window, not the elapsed
                // time: Swoole's read timer never fires early, so a
                // genuine timeout on a window of >= 0.05 s ALWAYS takes
                // >= 0.05 s, while a silent EOF returns in microseconds
                // (measured 0.09 ms on the stuck production pod). An
                // errCode-0 return from a window that large in well under
                // the 20 ms epsilon is an EOF — the stream is over and
                // polling it again only spins the reactor (measured
                // 2.8M iterations in 3 s in production). Close now instead
                // of waiting out the liveness deadline; the deadline stays
                // as the backstop for the half-open case, where reads time
                // out normally and only the silence betrays the stream.
                //
                // The >= 0.05 s window guard also keeps a shrunken
                // tail-window honest: if a partial frame consumed most of
                // this recv() call's budget, a final 10 ms read that
                // times out is still a timeout, not an EOF.
                if ($readWindow >= 0.05
                    && (microtime(true) - $readStart) < 0.02
                ) {
                    $this->closed = true;
                    throw new ConnectorException('gRPC stream closed (peer EOF reported without error)');
                }
                return null;  // genuine timeout
            }
            if (! isset($response->data)) {
                continue;  // empty frame, keep waiting
            }
            $this->readBuffer .= $response->data;
        }
    }

    private function tryParseFrame(): ?string
    {
        if (strlen($this->readBuffer) < 5) return null;
        $unpacked = unpack('Cflag/Nlen', substr($this->readBuffer, 0, 5));
        if ($unpacked === false) return null;
        $totalLen = 5 + (int) $unpacked['len'];
        if (strlen($this->readBuffer) < $totalLen) return null;
        $framed = substr($this->readBuffer, 5, $totalLen - 5);
        $this->readBuffer = substr($this->readBuffer, $totalLen);
        return $framed;
    }

    public function close(): void
    {
        @$this->http2->close();
    }
}
