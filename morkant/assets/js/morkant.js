// Morkant: entschlüsselt die Belohnung im Browser. Das Passwort steht nirgends im Code –
// nur mit dem richtigen Wort lässt sich der versiegelte Block öffnen.
(function () {
  const form = document.getElementById("gate");
  const input = document.getElementById("word");
  const btn = form.querySelector("button");
  const msg = document.getElementById("answer");
  const reward = document.getElementById("reward");
  const body = document.body;

  // Muss exakt zu normalize() in scripts/morkant-seal.mjs passen
  const normalize = (s) => s.normalize("NFC").trim().replace(/\s+/g, " ").toLocaleLowerCase("de");
  const fromB64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));

  let vault = null;
  try { vault = JSON.parse(document.getElementById("vault").textContent); } catch (e) {}
  if (!vault || !window.crypto || !crypto.subtle) {
    msg.textContent = "Dieser Browser kann das Siegel nicht lesen.";
    btn.disabled = true;
    return;
  }

  const REFUSALS = [
    "Der Fährmann schweigt.",
    "Das Wasser bleibt still. Das ist nicht das Wort.",
    "Die Laterne flackert – und erlischt beinahe.",
    "Kein Ruder rührt sich.",
  ];
  let tries = 0;

  async function open(word) {
    const enc = new TextEncoder();
    const base = await crypto.subtle.importKey("raw", enc.encode(normalize(word)), "PBKDF2", false, ["deriveKey"]);
    const key = await crypto.subtle.deriveKey(
      { name: "PBKDF2", hash: "SHA-256", salt: fromB64(vault.salt), iterations: vault.iter },
      base, { name: "AES-GCM", length: 256 }, false, ["decrypt"]);
    const plain = await crypto.subtle.decrypt({ name: "AES-GCM", iv: fromB64(vault.iv) }, key, fromB64(vault.ct));
    return new TextDecoder().decode(plain);
  }

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const word = input.value;
    if (!word.trim()) { input.focus(); return; }
    btn.disabled = true;
    input.disabled = true;
    body.classList.add("is-thinking");
    msg.textContent = "Der Fährmann lauscht …";
    try {
      const text = await open(word);
      show(text);
    } catch (err) {
      tries++;
      msg.textContent = REFUSALS[(tries - 1) % REFUSALS.length];
      form.classList.remove("is-wrong");
      void form.offsetWidth;
      form.classList.add("is-wrong");
      input.disabled = false;
      btn.disabled = false;
      input.select();
    } finally {
      body.classList.remove("is-thinking");
    }
  });

  function lines(box, list) {
    box.textContent = "";
    list.forEach((l) => { const p = document.createElement("p"); p.textContent = l; box.appendChild(p); });
  }

  function show(text) {
    const data = JSON.parse(text);
    reward.querySelector(".reward__title").textContent = data.title;
    reward.querySelector(".reward__question").textContent = data.question;
    const box = reward.querySelector(".reward__text");
    reward.querySelectorAll("[data-answer]").forEach((b) => b.addEventListener("click", () => {
      const yes = b.dataset.answer === "yes";
      reward.classList.add("is-answered", yes ? "is-yes" : "is-no");
      lines(box, yes ? data.yes : data.no);
      box.focus();
    }));
    body.classList.add("is-open");
    reward.hidden = false;
    msg.textContent = "";
    reward.focus();
  }
})();
