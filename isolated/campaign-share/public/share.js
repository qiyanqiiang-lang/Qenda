(function () {
  var root = document.getElementById("share-root");
  if (!root || root.getAttribute("data-enabled") !== "1" || root.getAttribute("data-invite-valid") !== "1") {
    return;
  }
  var dialog = document.getElementById("claim-dialog");
  var openBtn = document.getElementById("open-claim");
  var cancelBtn = document.getElementById("cancel-claim");
  var form = document.getElementById("claim-form");
  var errorBox = document.getElementById("claim-error");
  var resultBox = document.getElementById("claim-result");
  if (!dialog || !openBtn || !form || !errorBox || !resultBox) {
    return;
  }

  function csrf() {
    var node = document.querySelector('meta[name="csrf-token"]');
    return node ? node.getAttribute("content") || "" : "";
  }

  function post(path, payload) {
    return fetch(path, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "X-CSRF-Token": csrf()
      },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json();
    });
  }

  openBtn.addEventListener("click", function () {
    dialog.showModal();
  });
  if (cancelBtn) {
    cancelBtn.addEventListener("click", function () {
      dialog.close();
    });
  }
  if (location.hash === "#claim") {
    dialog.showModal();
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    errorBox.textContent = "";
    var phone = document.getElementById("phone").value.trim();
    var proof = document.getElementById("proof").value;
    if (!/^1[3-9]\d{9}$/.test(phone)) {
      errorBox.textContent = "手机号格式不正确，请核对后重试。";
      return;
    }
    post("/api/campaign-share/v1/challenge", {
      phone: phone,
      invite: root.getAttribute("data-invite")
    }).then(function (challenge) {
      if (!challenge.ok) {
        errorBox.textContent = challenge.message || "系统繁忙，请稍后再试。";
        return null;
      }
      return post("/api/campaign-share/v1/verify", {
        challenge_id: challenge.challenge_id,
        proof: proof
      });
    }).then(function (verified) {
      if (!verified) {
        return null;
      }
      if (!verified.ok) {
        errorBox.textContent = verified.message || "系统繁忙，请稍后再试。";
        return null;
      }
      return post("/api/campaign-share/v1/claim", {});
    }).then(function (claimed) {
      if (!claimed) {
        return;
      }
      if (!claimed.ok) {
        errorBox.textContent = claimed.message || "系统繁忙，请稍后再试。";
        return;
      }
      resultBox.hidden = false;
      document.getElementById("result-title").textContent = claimed.title || "";
      document.getElementById("result-status").textContent = claimed.status_label || "";
      document.getElementById("result-key").textContent = claimed.key || "";
      document.getElementById("result-phone").textContent = claimed.phone || "";
      document.getElementById("result-hint").textContent = claimed.hint || "";
    }).catch(function () {
      errorBox.textContent = "系统繁忙，请稍后再试。";
    });
  });

  var go = document.getElementById("go-use");
  if (go) {
    go.addEventListener("click", function () {
      // Server returns the existing ?key= prefill. This click does not mark the key used.
      post("/api/campaign-share/v1/go-use", {}).then(function (data) {
        if (data && data.url) {
          window.location.assign(data.url);
        }
      });
    });
  }
})();
