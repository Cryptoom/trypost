---
paths:
  - 'app/Services/Social/**'
---

# Social

## Resume asynchronous publishes without duplication
Never mark an asynchronous social publish as successful before the platform reports its terminal success state. On timeout, classify it as temporarily unavailable and preserve any remote operation/publish ID in retry context so a delayed retry resumes status polling instead of initiating a duplicate post.
