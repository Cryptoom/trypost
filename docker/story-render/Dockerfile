# syntax=docker/dockerfile:1.7

# ----------------------------------------------------------------------------
# story-render: internal service that turns a photo plus an optional headline
# into a Facebook-Story MP4 with HyperFrames (HTML composition, headless Chromium).
#
#   docker build -t trypost-story-render docker/story-render
#
# Run hardened, no published port, reachable only on the internal compose network:
#   --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges:true
#   --user node --pids-limit 256 --memory 3g --memory-swap 3g --cpus 2 --shm-size 1g --init
# STORY_RENDER_TOKEN is required, the service refuses to start without it.
# ----------------------------------------------------------------------------
FROM node:22-bookworm-slim

ENV NODE_ENV=production \
    PORT=3000 \
    DO_NOT_TRACK=1 \
    HYPERFRAMES_NO_TELEMETRY=1 \
    HYPERFRAMES_NO_UPDATE_CHECK=1 \
    HYPERFRAMES_BROWSER_PATH=/usr/bin/chromium

RUN apt-get update \
    && apt-get install -y --no-install-recommends chromium ffmpeg ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# hyperframes and gsap are pinned in package.json and package-lock.json and installed
# here at build time, never fetched at run time.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts --no-audit --no-fund \
    && npm cache clean --force

COPY server.mjs ./
COPY lib ./lib
COPY template ./template

USER node

EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["node", "-e", "fetch('http://127.0.0.1:3000/health').then((r) => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"]

CMD ["node", "server.mjs"]
