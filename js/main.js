/**
 * MICHAŁ WILKOSZ — michalwilkosz.eu
 * js/main.js — vanilla JS, zero zależności, zero build stepu.
 *
 * Spis treści:
 *   1. Konfiguracja endpointów (do podłączenia później)
 *   2. Nagłówek — zmiana stanu przy scrollu
 *   3. Menu mobilne (overlay pełnoekranowy)
 *   4. Reveal przy scrollu (IntersectionObserver)
 *   5. Subtelny parallax (tylko desktop, wyłączony przy reduced-motion)
 *   6. Disclosure "Czytaj więcej" (O mnie + Szybex)
 *   6b. Dot pagination dla poziomych swipe-list (Szybex, Zasady, Obszary)
 *   8. Obsługa formularzy (newsletter + kontakt)
 *   9. Rok w stopce
 *   10. Bootstrap
 */

(function () {
  "use strict";

  /* --------------------------------------------------------------------
   * 1. KONFIGURACJA ENDPOINTÓW
   * Puste stringi = tryb podglądu (formularz waliduje i pokazuje success,
   * ale nic nie wysyła). Podłącz tu docelowy adres API, gdy będzie gotowy
   * (Mailchimp / ConvertKit / Brevo / własny backend / Formspree itp.).
   * Nie hardkodujemy konkretnego dostawcy — patrz brief, sekcja 13 i 20.
   * ------------------------------------------------------------------ */
  const ENDPOINTS = {
    newsletter: "/api/newsletter.php", // MailerLite — patrz api/newsletter.php
    contact: "/api/send-mail.php", // SMTP (PHPMailer) — patrz api/send-mail.php
  };

  const prefersReducedMotion = window.matchMedia(
    "(prefers-reduced-motion: reduce)"
  ).matches;

  /* --------------------------------------------------------------------
   * 2. NAGŁÓWEK — zmiana stanu przy scrollu
   * ------------------------------------------------------------------ */
  function initHeaderScroll() {
    const header = document.getElementById("site-header");
    if (!header) return;

    const updateHeaderState = () => {
      header.classList.toggle("is-scrolled", window.scrollY > 80);
    };

    updateHeaderState();
    window.addEventListener("scroll", updateHeaderState, { passive: true });
  }

  /* --------------------------------------------------------------------
   * 3. MENU MOBILNE
   * ------------------------------------------------------------------ */
  function initMobileNav() {
    const toggle = document.getElementById("menuToggle");
    const nav = document.getElementById("mobileNav");
    const main = document.getElementById("main");
    const footer = document.querySelector(".site-footer");
    if (!toggle || !nav) return;

    // Gdy overlay jest otwarty, reszta strony jest wyłączona z fokusu/czytników
    // ekranu (inert + aria-hidden), żeby Tab nie „wyciekał" pod spód.
    const setBackgroundInert = (isInert) => {
      [main, footer].forEach((el) => {
        if (!el) return;
        if (isInert) {
          el.setAttribute("inert", "");
          el.setAttribute("aria-hidden", "true");
        } else {
          el.removeAttribute("inert");
          el.removeAttribute("aria-hidden");
        }
      });
    };

    const openNav = () => {
      nav.dataset.state = "open";
      toggle.setAttribute("aria-expanded", "true");
      document.body.style.overflow = "hidden";
      setBackgroundInert(true);
      const firstLink = nav.querySelector("a");
      if (firstLink) firstLink.focus();
    };

    const closeNav = () => {
      nav.dataset.state = "closed";
      toggle.setAttribute("aria-expanded", "false");
      document.body.style.overflow = "";
      setBackgroundInert(false);
      toggle.focus();
    };

    toggle.addEventListener("click", () => {
      const isOpen = toggle.getAttribute("aria-expanded") === "true";
      isOpen ? closeNav() : openNav();
    });

    nav.querySelectorAll("a").forEach((link) => {
      link.addEventListener("click", closeNav);
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && nav.dataset.state === "open") closeNav();
    });
  }

  /* --------------------------------------------------------------------
   * 4. REVEAL PRZY SCROLLU
   * ------------------------------------------------------------------ */
  function initRevealAnimations() {
    const targets = document.querySelectorAll(".reveal");
    if (!targets.length) return;

    if (!("IntersectionObserver" in window)) {
      targets.forEach((el) => el.classList.add("is-visible"));
      return;
    }

    const observer = new IntersectionObserver(
      (entries, obs) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          entry.target.classList.add("is-visible");
          obs.unobserve(entry.target);
        });
      },
      { threshold: 0.15 }
    );

    targets.forEach((el) => observer.observe(el));
  }

  /* --------------------------------------------------------------------
   * 5. SUBTELNY PARALLAX (desktop, opt-out przy reduced-motion)
   * ------------------------------------------------------------------ */
  function initParallax() {
    if (prefersReducedMotion || window.innerWidth < 900) return;
    const targets = document.querySelectorAll(".parallax");
    if (!targets.length) return;

    let ticking = false;

    const update = () => {
      targets.forEach((el) => {
        const rect = el.getBoundingClientRect();
        const progress = (rect.top + rect.height / 2 - window.innerHeight / 2) / window.innerHeight;
        const clamped = Math.max(-1, Math.min(1, progress));
        el.style.transform = `translateY(${clamped * -24}px)`;
      });
      ticking = false;
    };

    window.addEventListener(
      "scroll",
      () => {
        if (!ticking) {
          window.requestAnimationFrame(update);
          ticking = true;
        }
      },
      { passive: true }
    );
    update();
  }

  /* --------------------------------------------------------------------
   * 6. DISCLOSURE "CZYTAJ WIĘCEJ" (sekcja Szybex)
   * Prosty editorial disclosure: button z aria-expanded/aria-controls,
   * animacja przez max-height (mierzony scrollHeight, nie zgadywany) +
   * opacity. Bez JS treść zostaje po prostu zwinięta (button nic nie robi) —
   * reszta strony nie zależy od tej funkcji, więc to akceptowalny fallback.
   * ------------------------------------------------------------------ */
  function initDisclosure(toggleId, contentId) {
    const toggle = document.getElementById(toggleId);
    const content = document.getElementById(contentId);
    if (!toggle || !content) return;

    const label = toggle.querySelector(".disclosure__label");
    // Zamknięty label = to, co faktycznie stoi w HTML (nie hardkodowany
    // "Czytaj więcej") — initDisclosure jest reużywany dla About ("Poznaj
    // moją historię") i Szybex ("Czytaj więcej"), więc każda instancja musi
    // wracać do WŁASNEGO oryginalnego tekstu po zwinięciu.
    const closedLabel = label ? label.textContent : "";
    // Ikona (↓) nie zmienia znaku w JS — CSS obraca ją o 180° na podstawie
    // aria-expanded, więc zmiana samego tekstu tutaj podwójnie odwracałaby
    // strzałkę (raz przez JS, raz przez CSS transform).

    const settleAfterTransition = (onEnd) => {
      const handler = (event) => {
        if (event.propertyName !== "max-height") return;
        content.removeEventListener("transitionend", handler);
        onEnd();
      };
      content.addEventListener("transitionend", handler);
    };

    const open = () => {
      content.hidden = false;
      content.style.maxHeight = "0px";
      // Podwójny rAF: przeglądarka musi „zobaczyć" stan 0px zanim zmienimy
      // na docelową wysokość, inaczej transition się nie uruchomi.
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          content.classList.add("is-open");
          content.style.maxHeight = `${content.scrollHeight}px`;
        });
      });
      settleAfterTransition(() => {
        content.style.maxHeight = "none"; // nie tnij treści przy zmianie rozmiaru okna
      });
      toggle.setAttribute("aria-expanded", "true");
      if (label) label.textContent = "Zwiń";
    };

    const close = () => {
      content.style.maxHeight = `${content.scrollHeight}px`;
      requestAnimationFrame(() => {
        content.classList.remove("is-open");
        content.style.maxHeight = "0px";
      });
      settleAfterTransition(() => {
        content.hidden = true;
        content.style.maxHeight = "";
      });
      toggle.setAttribute("aria-expanded", "false");
      if (label) label.textContent = closedLabel;
    };

    toggle.addEventListener("click", () => {
      const isOpen = toggle.getAttribute("aria-expanded") === "true";
      isOpen ? close() : open();
    });
  }

  /* --------------------------------------------------------------------
   * 6b. DOT PAGINATION DLA POZIOMYCH SWIPE-LIST (Szybex timeline, Zasady,
   * Obszary) — jeden reużywalny komponent zamiast tekstowego licznika
   * "01 / 05". Generuje przyciski-kropki, aktualizuje aria-current na
   * scroll (element najbliższy lewej krawędzi kontenera = "aktywny"),
   * klik w kropkę scrolluje do odpowiedniego slajdu.
   * ------------------------------------------------------------------ */
  function initCarouselDots(containerId, itemSelector, dotsId) {
    const container = document.getElementById(containerId);
    const dotsWrap = document.getElementById(dotsId);
    if (!container || !dotsWrap) return;

    const items = Array.from(container.querySelectorAll(itemSelector));
    if (!items.length) return;

    // Kontener z ujemnym marginesem + scroll-padding-left (bleed do krawędzi
    // viewportu, patrz CSS) potrafi wystartować z scrollLeft > 0 zamiast 0
    // przy tej kombinacji z scroll-snap — wymuszamy poprawną pozycję startową.
    container.scrollLeft = 0;

    dotsWrap.innerHTML = "";
    const dots = items.map((item, index) => {
      const dot = document.createElement("button");
      dot.type = "button";
      dot.className = "carousel-dots__dot";
      dot.setAttribute("aria-label", `Przejdź do slajdu ${index + 1}`);
      dot.addEventListener("click", () => {
        item.scrollIntoView({
          behavior: prefersReducedMotion ? "auto" : "smooth",
          block: "nearest",
          inline: "start",
        });
      });
      dotsWrap.appendChild(dot);
      return dot;
    });

    const setActive = (activeIndex) => {
      dots.forEach((dot, index) => {
        if (index === activeIndex) {
          dot.setAttribute("aria-current", "true");
        } else {
          dot.removeAttribute("aria-current");
        }
      });
    };

    const update = () => {
      const containerLeft = container.getBoundingClientRect().left;
      let closestIndex = 0;
      let closestDistance = Infinity;
      items.forEach((item, index) => {
        const distance = Math.abs(item.getBoundingClientRect().left - containerLeft);
        if (distance < closestDistance) {
          closestDistance = distance;
          closestIndex = index;
        }
      });
      setActive(closestIndex);
    };

    let ticking = false;
    container.addEventListener(
      "scroll",
      () => {
        if (!ticking) {
          window.requestAnimationFrame(() => {
            update();
            ticking = false;
          });
          ticking = true;
        }
      },
      { passive: true }
    );

    update();
  }

  /* --------------------------------------------------------------------
   * 8. OBSŁUGA FORMULARZY (newsletter + kontakt)
   * ------------------------------------------------------------------ */
  function wait(ms) {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
  }

  function setFormState(form, state) {
    form.dataset.state = state;
  }

  async function submitForm(form, endpointKey) {
    // Honeypot — jeśli wypełnione, to bot: udajemy sukces, nic nie wysyłamy.
    const honeypot = form.querySelector(".hp-field input");
    if (honeypot && honeypot.value) {
      setFormState(form, "success");
      return;
    }

    const submitBtn = form.querySelector('[type="submit"]');
    // Zmiana tekstu przycisku na czas wysyłki jest OPT-IN przez
    // data-submitting-label (obecnie tylko #contactForm) — newsletter nie
    // ma tego atrybutu, więc jego przycisk zachowuje się DOKŁADNIE tak jak
    // wcześniej (tylko disabled, bez zmiany tekstu).
    const submittingLabel = submitBtn ? submitBtn.dataset.submittingLabel : null;
    const originalLabel = submitBtn ? submitBtn.textContent : null;
    if (submitBtn) {
      submitBtn.disabled = true;
      if (submittingLabel) submitBtn.textContent = submittingLabel;
    }
    setFormState(form, "submitting");

    const endpoint = ENDPOINTS[endpointKey];
    const payload = Object.fromEntries(new FormData(form).entries());

    try {
      if (!endpoint) {
        console.info(
          `[main.js] ENDPOINTS.${endpointKey} nie jest skonfigurowany — ` +
            "formularz działa w trybie podglądu (dane nie są nigdzie wysyłane). " +
            "Podłącz docelowy endpoint w js/main.js, aby aktywować realną wysyłkę."
        );
        await wait(500);
      } else {
        const response = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error(`Odpowiedź serwera: ${response.status}`);
      }
      setFormState(form, "success");
      // Czyścimy pola po sukcesie — bez widocznego efektu na newsletterze
      // (jego pola i tak znikają pod success-state'em, patrz CSS
      // .form[data-state="success"] .form-fields{display:none}), ale
      // dokładnie to, o co prosi brief dla formularza kontaktowego.
      form.reset();
    } catch (error) {
      console.error("[main.js] Błąd wysyłki formularza:", error);
      setFormState(form, "error");
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        if (submittingLabel && originalLabel !== null) submitBtn.textContent = originalLabel;
      }
    }
  }

  function initForm(formId, endpointKey) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener("submit", (event) => {
      event.preventDefault();

      // Natywna walidacja HTML5 — dostępna, zlokalizowana przez przeglądarkę.
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      submitForm(form, endpointKey);
    });
  }

  /* --------------------------------------------------------------------
   * 9. ROK W STOPCE
   * ------------------------------------------------------------------ */
  function initFooterYear() {
    const el = document.getElementById("footerYear");
    if (el) el.textContent = new Date().getFullYear();
  }

  /* --------------------------------------------------------------------
   * 10. BOOTSTRAP
   * ------------------------------------------------------------------ */
  function init() {
    initHeaderScroll();
    initMobileNav();
    initRevealAnimations();
    initParallax();
    initDisclosure("szybexToggle", "szybexMore");
    initDisclosure("aboutToggle", "aboutMore");
    initCarouselDots("szybexTimeline", ".timeline-anchor", "szybexTimelineDots");
    initCarouselDots("principlesList", ".principle", "principlesDots");
    initCarouselDots("obszaryCarousel", ".topic", "obszaryDots");
    initCarouselDots("praktykaList", ".praktyka-entry", "praktykaDots");
    initForm("newsletterForm", "newsletter");
    initForm("contactForm", "contact");
    initFooterYear();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
