(function () {
  "use strict";

  var body = document.body;
  var header = document.getElementById("siteHeader");
  var navToggle = document.getElementById("navToggle");
  var primaryNavigation = document.getElementById("primaryNavigation");
  var modal = document.getElementById("wechatModal");
  var modalClose = document.getElementById("modalClose");
  var modalCloseBottom = document.getElementById("modalCloseBottom");
  var lastFocusedElement = null;
  window.dataLayer = window.dataLayer || [];

  function setHeaderState() {
    if (!header) return;
    header.classList.toggle("is-scrolled", window.scrollY > 12);
  }

  function closeNavigation() {
    if (!navToggle || !primaryNavigation) return;
    navToggle.setAttribute("aria-expanded", "false");
    primaryNavigation.classList.remove("is-open");
    body.classList.remove("menu-open");
  }

  function toggleNavigation() {
    if (!navToggle || !primaryNavigation) return;
    var isOpen = navToggle.getAttribute("aria-expanded") === "true";
    navToggle.setAttribute("aria-expanded", String(!isOpen));
    primaryNavigation.classList.toggle("is-open", !isOpen);
    body.classList.toggle("menu-open", !isOpen);
  }

  function getFocusableElements(container) {
    return Array.prototype.slice.call(
      container.querySelectorAll(
        'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )
    );
  }

  function openWechatModal() {
    if (!modal) return;
    lastFocusedElement = document.activeElement;
    modal.classList.add("is-active");
    modal.setAttribute("aria-hidden", "false");
    body.classList.add("modal-open");
    closeNavigation();
    window.setTimeout(function () {
      if (modalClose) modalClose.focus();
    }, 0);
  }

  function closeWechatModal() {
    if (!modal) return;
    modal.classList.remove("is-active");
    modal.setAttribute("aria-hidden", "true");
    body.classList.remove("modal-open");
    if (lastFocusedElement && typeof lastFocusedElement.focus === "function") {
      lastFocusedElement.focus();
    }
  }

  function handleModalKeydown(event) {
    if (!modal || !modal.classList.contains("is-active")) return;

    if (event.key === "Escape") {
      closeWechatModal();
      return;
    }

    if (event.key !== "Tab") return;

    var focusableElements = getFocusableElements(modal);
    if (!focusableElements.length) return;

    var firstElement = focusableElements[0];
    var lastElement = focusableElements[focusableElements.length - 1];

    if (event.shiftKey && document.activeElement === firstElement) {
      event.preventDefault();
      lastElement.focus();
    } else if (!event.shiftKey && document.activeElement === lastElement) {
      event.preventDefault();
      firstElement.focus();
    }
  }

  function initCarousel() {
    var carousel = document.getElementById("dataCarousel");
    var track = document.getElementById("carouselTrack");
    var slides = track ? Array.prototype.slice.call(track.querySelectorAll(".carousel-slide")) : [];
    var previousButton = document.getElementById("carouselPrev");
    var nextButton = document.getElementById("carouselNext");
    var dotsContainer = document.getElementById("carouselDots");
    var currentIndex = 0;
    var intervalId = null;

    if (!carousel || !track || !slides.length) return;

    function renderDots() {
      if (!dotsContainer) return;
      dotsContainer.innerHTML = "";

      slides.forEach(function (_, index) {
        var dot = document.createElement("button");
        dot.className = "carousel-dot" + (index === currentIndex ? " is-active" : "");
        dot.type = "button";
        dot.setAttribute("aria-label", "查看第 " + (index + 1) + " 张投放数据");
        dot.setAttribute("aria-current", index === currentIndex ? "true" : "false");
        dot.addEventListener("click", function () {
          goTo(index);
          restartCarousel();
        });
        dotsContainer.appendChild(dot);
      });
    }

    function goTo(index) {
      currentIndex = (index + slides.length) % slides.length;
      track.style.transform = "translateX(-" + currentIndex * 100 + "%)";

      slides.forEach(function (slide, slideIndex) {
        slide.classList.toggle("is-active", slideIndex === currentIndex);
      });

      if (dotsContainer) {
        Array.prototype.forEach.call(dotsContainer.children, function (dot, dotIndex) {
          var isActive = dotIndex === currentIndex;
          dot.classList.toggle("is-active", isActive);
          dot.setAttribute("aria-current", isActive ? "true" : "false");
        });
      }
    }

    function next() {
      goTo(currentIndex + 1);
    }

    function stopCarousel() {
      window.clearInterval(intervalId);
      intervalId = null;
    }

    function startCarousel() {
      if (intervalId || window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
      intervalId = window.setInterval(next, 3500);
    }

    function restartCarousel() {
      stopCarousel();
      startCarousel();
    }

    renderDots();
    goTo(0);
    startCarousel();

    if (previousButton) {
      previousButton.addEventListener("click", function () {
        goTo(currentIndex - 1);
        restartCarousel();
      });
    }

    if (nextButton) {
      nextButton.addEventListener("click", function () {
        goTo(currentIndex + 1);
        restartCarousel();
      });
    }

    carousel.addEventListener("mouseenter", stopCarousel);
    carousel.addEventListener("mouseleave", startCarousel);
    carousel.addEventListener("focusin", stopCarousel);
    carousel.addEventListener("focusout", function (event) {
      if (!carousel.contains(event.relatedTarget)) startCarousel();
    });

    document.addEventListener("visibilitychange", function () {
      if (document.hidden) {
        stopCarousel();
      } else {
        startCarousel();
      }
    });
  }

  function initSectionObserver() {
    if (!("IntersectionObserver" in window)) return;

    var links = Array.prototype.slice.call(document.querySelectorAll(".nav-links a[href^='#']"));
    var sections = links
      .map(function (link) {
        return document.querySelector(link.getAttribute("href"));
      })
      .filter(Boolean);

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          links.forEach(function (link) {
            var isCurrent = link.getAttribute("href") === "#" + entry.target.id;
            if (isCurrent) {
              link.setAttribute("aria-current", "page");
            } else {
              link.removeAttribute("aria-current");
            }
          });
        });
      },
      { rootMargin: "-35% 0px -58% 0px", threshold: 0 }
    );

    sections.forEach(function (section) {
      observer.observe(section);
    });
  }

  window.addEventListener("scroll", setHeaderState, { passive: true });
  setHeaderState();

  if (navToggle) {
    navToggle.addEventListener("click", toggleNavigation);
  }

  if (primaryNavigation) {
    primaryNavigation.addEventListener("click", function (event) {
      if (event.target.closest("a")) closeNavigation();
    });
  }

  document.addEventListener("click", function (event) {
    var trigger = event.target.closest("[data-wechat-trigger]");
    if (trigger) {
      var sourceSection = trigger.closest("section");
      window.dataLayer.push({
        event: "wechat_consult_click",
        source: sourceSection ? sourceSection.id : "global"
      });
      openWechatModal();
    }
  });

  if (modal) {
    modal.addEventListener("click", function (event) {
      if (event.target === modal) closeWechatModal();
    });
  }

  if (modalClose) modalClose.addEventListener("click", closeWechatModal);
  if (modalCloseBottom) modalCloseBottom.addEventListener("click", closeWechatModal);
  document.addEventListener("keydown", handleModalKeydown);

  window.addEventListener("resize", function () {
    if (window.innerWidth > 860) closeNavigation();
  });

  initCarousel();
  initSectionObserver();
})();
