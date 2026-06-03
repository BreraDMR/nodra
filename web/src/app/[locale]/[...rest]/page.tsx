import { notFound } from "next/navigation";

// any unknown path under a language gets that language's 404 inside the shop layout
export default function UnknownPage() {
  notFound();
}
