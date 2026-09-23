// Vercel Serverless Function
// このファイルはサーバー側で実行されるため、ブラウザからAPIキーは見えません。

const MODEL = "gemini-3.8-flash";

function readingText(result) {
  const parts = result?.candidates?.[0]?.content?.parts || [];
  return parts
    .filter((part) => part && part.text && !part.thought)
    .map((part) => part.text)
    .join("\n")
    .trim();
}

export default async function handler(req, res) {
  if (req.method !== "POST") {
    return res.status(405).json({ error: "この操作はできません。" });
  }

  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) {
    return res.status(500).json({ error: "サーバーにAPIキーが設定されていません。" });
  }

  const image = req.body && req.body.image;
  const mimeType = req.body && req.body.mimeType === "image/png" ? "image/png" : "image/jpeg";
  if (typeof image !== "string" || image.length < 100) {
    return res.status(400).json({ error: "顔の画像を取得できませんでした。カメラを向けて、もう一度お試しください。" });
  }
  if (image.length > 3500000) {
    return res.status(413).json({ error: "画像が大きすぎます。もう一度お試しください。" });
  }

  const prompt = `これは利用者が自分でカメラに向かって撮った写真です。娯楽としての人相占いをしてください。身元の特定や医療診断はしないでください。
顔が少しでも写っていれば、背景の説明では断らず、その顔を読んでください。顔がまったくないときだけ、正面を向くよう短く案内してください。
次の3つの見出しだけを、それぞれ独立した行として書いてください。見出しに # や * は付けないでください。
顔から読み取れる性格
いまの運気
これからへのアドバイス
各見出しのあとに、丁寧な語りかける口調で2段落書いてください。`;

  const payload = {
    contents: [{
      role: "user",
      parts: [
        { text: prompt },
        { inlineData: { mimeType, data: image } }
      ]
    }],
    generationConfig: {
      thinkingConfig: { thinkingLevel: "low" },
      maxOutputTokens: 2048
    }
  };

  try {
    const response = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/${MODEL}:generateContent?key=${apiKey}`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      }
    );

    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      const detail = String(result?.error?.message || "").slice(0, 300);
      console.error("gemini_error", response.status, result?.error?.status || "", detail);
      const friendly = response.status === 429 || response.status === 503
        ? "いま鑑定が混み合っています。少し待ってから、もう一度お試しください。"
        : "鑑定できませんでした。顔が映っていることを確認して、もう一度お試しください。";
      return res.status(502).json({ error: friendly });
    }

    if (result?.promptFeedback?.blockReason) {
      return res.status(422).json({
        error: "この画像では鑑定できませんでした。顔がはっきり写るようにして、もう一度お試しください。"
      });
    }

    const text = readingText(result);
    if (!text) {
      return res.status(502).json({ error: "鑑定結果が得られませんでした。もう一度お試しください。" });
    }

    return res.status(200).json({ text });
  } catch (err) {
    return res.status(500).json({ error: "鑑定中に問題が起きました。少し待ってから、もう一度お試しください。" });
  }
}