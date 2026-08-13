---
paths:
  - app/Console/Commands/RetryFailedPost.php
---

# Commands

## Manual retries start fresh publish attempts
The posts:retry command may only reset enabled post_platforms in Failed status for a Failed or PartiallyPublished post. Manual retries are full republishes: clear remote IDs, URLs, published/error fields, and retry checkpoints; prune any old TikTok photo derivatives before dispatching a new PublishToSocialPlatform job. Never reset already-published platforms.
