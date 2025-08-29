document.addEventListener("DOMContentLoaded", () => {
  const liffId = "2008005425-w5zrAGqk";
  const urlParams = new URLSearchParams(window.location.search);
  const groupId = urlParams.get("groupId");
  liff
    .init({ liffId })
    .then(() => {
      if (!liff.isLoggedIn()) {
        liff.login();
        return;
      }

      const form = document.getElementById("bill-form");
      const submitBtn = document.getElementById("submit-btn");
      const context = liff.getContext();
      const userId = context.userId;

      if (!groupId) {
        alert("無法獲取情境資訊，請在群組或一對一聊天中開啟。");
        form.innerHTML = "<p>錯誤：無法獲取情境資訊。</p>";
        submitBtn.disabled = true;
        return;
      }

      // 獲取成員名單
      fetch(
        `https://bot.patrickzzz.com/liff/liff-api.php?action=get_members&groupId=${groupId}`
      )
        .then((response) => {
          if (!response.ok)
            throw new Error(`API 請求失敗，狀態碼: ${response.status}`);
          return response.json();
        })
        .then((data) => {
          if (!data || !Array.isArray(data.members)) {
            throw new Error("從 API 收到的資料格式不正確。");
          }

          if (data.members.length === 0) {
            form.innerHTML =
              "<p>此群組尚無註冊成員，請先在聊天室中點擊「成為分母++」。</p>";
            submitBtn.disabled = true;
            return;
          }

          data.members.forEach((member) => {
            const div = document.createElement("div");
            div.className = "member-item";
            div.innerHTML = `
              <input type="checkbox" id="${member.userId}" name="participants[]" value="${member.userId}">
              <label for="${member.userId}">${member.displayName}</label>
            `;
            form.appendChild(div);
          });
        })
        .catch((error) => {
          console.error("獲取成員名單時發生錯誤:", error);
          alert("無法載入成員名單，請確認後端 API 是否正常運作。");
          form.innerHTML = `<p style="color: red;">載入成員失敗: ${error.message}</p>`;
          submitBtn.disabled = true;
        });

      // 表單送出邏輯
      submitBtn.addEventListener("click", () => {
        const selectedMembers = Array.from(
          document.querySelectorAll('input[name="participants[]"]:checked')
        ).map((checkbox) => checkbox.value);

        const amount = prompt("請輸入總金額：", "1000");
        if (!amount || isNaN(amount) || Number(amount) <= 0) {
          alert("請輸入有效的金額！");
          return;
        }

        fetch("https://bot.patrickzzz.com/liff/liff-api.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            groupId: groupId,
            payerId: userId,
            participants: selectedMembers,
            amount: Number(amount),
          }),
        })
          .then((response) =>
            response.ok
              ? response.json()
              : response.json().then((err) => Promise.reject(err))
          )
          .then((data) => {
            alert(data.message || "帳單新增成功！");
            liff.closeWindow();
          })
          .catch((err) => alert(`新增失敗: ${err.message || "請檢查主控台"}`));
      });
    })
    .catch((err) => {
      console.error("LIFF 初始化或執行時發生錯誤:", err);
      alert("LIFF 應用程式發生錯誤，請查看主控台日誌。");
    });
});
